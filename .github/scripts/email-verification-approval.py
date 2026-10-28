"""Read-only GitHub approval/provenance gate. Never prints responses or tokens."""

import json
import os
import re
import sys
import urllib.request

REPOSITORY = "cjahhh/pwdwork"
WORKFLOW = ".github/workflows/email-verification-incremental.yml"


def demand(condition):
    if not condition:
        raise ValueError("approval_or_provenance_rejected")


def approval(reviews, environment, actors):
    relevant = [r for r in reviews if any(
        e.get("name") == environment for e in r.get("environments", []))]
    # Reject ambiguous histories, rejected gates, bypass and self-approval.
    demand(len(relevant) == 1)
    review = relevant[0]
    user = review.get("user", {})
    demand(review.get("state") == "approved" and user.get("type") == "User")
    login = user.get("login", "").lower()
    demand(bool(login) and login not in {a.lower() for a in actors})


def provenance(run, receipt, current):
    demand(str(run.get("id")) == receipt.get("run_id"))
    demand(run.get("repository", {}).get("full_name") == REPOSITORY)
    demand(run.get("path") in (WORKFLOW, WORKFLOW + "@main") and run.get("head_branch") == "main")
    demand(run.get("head_sha") == current and receipt.get("sha") == current)
    demand(run.get("event") == "workflow_dispatch" and run.get("run_attempt") == 1)
    demand(run.get("conclusion") == "success" and run.get("status") == "completed")
    demand(run.get("display_title") == "Email migration PREVIEW")


def get(path):
    request = urllib.request.Request(
        "https://api.github.com/repos/" + REPOSITORY + path,
        headers={"Authorization": "Bearer " + os.environ["GH_TOKEN"],
                 "Accept": "application/vnd.github+json",
                 "X-GitHub-Api-Version": "2022-11-28"})
    # Fixed API origin/paths only. Do not log URLs, bodies, headers or exceptions.
    with urllib.request.urlopen(request, timeout=30) as response:
        return json.load(response)


def main():
    try:
        demand(os.environ["GITHUB_REPOSITORY"] == REPOSITORY)
        demand(os.environ["GITHUB_EVENT_NAME"] == "workflow_dispatch")
        demand(os.environ["GITHUB_REF"] == "refs/heads/main")
        demand(os.environ["GITHUB_RUN_ATTEMPT"] == "1")
        operation = os.environ["OPERATION"]
        demand(operation in ("PREVIEW", "APPLY"))
        sha = os.environ["GITHUB_SHA"]
        demand(re.fullmatch(r"[a-f0-9]{40}", sha) and os.environ["REVIEWED_SHA"] == sha)
        run_id = os.environ["GITHUB_RUN_ID"]
        demand(re.fullmatch(r"[1-9][0-9]*", run_id))
        reviews = get("/actions/runs/" + run_id + "/approvals")
        approval(reviews, "pwdwork-email-migration-" + operation.lower(),
                 [os.environ["GITHUB_ACTOR"], os.environ["GITHUB_TRIGGERING_ACTOR"]])
        if operation == "APPLY":
            demand(os.environ["CONFIRMATION"] == "APPLY email ownership pwdwork_prod")
            receipt = json.loads(os.environ["EV_APPROVED_PREVIEW"])
            preview_id = receipt["run_id"]
            demand(re.fullmatch(r"[1-9][0-9]*", preview_id) and preview_id != run_id)
            provenance(get("/actions/runs/" + preview_id), receipt, sha)
        print("PASS: separate human approval and run provenance")
        return 0
    except Exception:
        print("FAIL: approval/provenance gate; no database credentials released to runner")
        return 1


if __name__ == "__main__":
    sys.exit(main())
