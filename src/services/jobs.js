import api from "./api.js";

function listingFingerprint(listing) {
  return [
    listing.company,
    listing.title,
    listing.location,
    listing.type,
    listing.mode,
    listing.description,
  ]
    .map((value) =>
      String(value || "")
        .trim()
        .toLowerCase(),
    )
    .join("|");
}

export function dedupeListings(listings) {
  return listings.filter(
    (listing, index, all) =>
      all.findIndex(
        (candidate) =>
          listingFingerprint(candidate) === listingFingerprint(listing),
      ) === index,
  );
}

export function dedupeApplicants(applicants) {
  return applicants.filter((applicant, index, all) => {
    const key = [
      applicant.email || applicant.name,
      applicant.jobTitle,
      applicant.company,
    ]
      .map((value) =>
        String(value || "")
          .trim()
          .toLowerCase(),
      )
      .join("|");
    return (
      all.findIndex(
        (candidate) =>
          [
            candidate.email || candidate.name,
            candidate.jobTitle,
            candidate.company,
          ]
            .map((value) =>
              String(value || "")
                .trim()
                .toLowerCase(),
            )
            .join("|") === key,
      ) === index
    );
  });
}

export async function fetchJobs(params = {}) {
  const { data } = await api.get("/jobs", { params });
  return data;
}

export async function fetchJob(id) {
  const { data } = await api.get(`/jobs/${id}`);
  return data.job;
}

export function mapJobToApiPayload(listing) {
  return {
    title: listing.title,
    location: listing.location,
    employment_type: listing.type,
    work_mode: listing.mode,
    description: listing.description,
    accommodations:
      listing.accommodations?.map((accommodation) =>
        typeof accommodation === "string" ? accommodation : accommodation.label,
      ) || [],
    flexible_schedule: Boolean(listing.flexibleSchedule),
  };
}

export async function fetchEmployerJobs(params = {}) {
  const { data } = await api.get("/employer/jobs", { params });
  return data;
}

export async function createEmployerJob(listing) {
  const { data } = await api.post(
    "/employer/jobs",
    mapJobToApiPayload(listing),
  );
  return data.job;
}

export async function updateEmployerJobStatus(id, status) {
  const { data } = await api.patch(`/employer/jobs/${id}`, {
    status: status.toLowerCase(),
  });
  return data.job;
}

export async function updateEmployerJob(id, listing) {
  const { data } = await api.patch(
    `/employer/jobs/${id}`,
    mapJobToApiPayload(listing),
  );
  return data.job;
}

export async function applyToJob(id, coverLetter = "", shareAccommodations = false) {
  const { data } = await api.post(`/jobs/${id}/applications`, {
    cover_letter: coverLetter,
    share_accommodations: shareAccommodations,
  });
  return data.application;
}

export async function fetchEmployerApplications(params = {}) {
  const { data } = await api.get("/employer/applications", { params });
  return data;
}

export async function updateEmployerApplication(id, status, schedule = {}) {
  const statusMap = {
    New: "submitted",
    Reviewing: "reviewing",
    Interview: "interview",
    Shortlisted: "shortlisted",
    Hired: "hired",
    Rejected: "rejected",
  };
  const { data } = await api.patch(`/employer/applications/${id}`, {
    status: statusMap[status] || status,
    ...schedule,
  });
  return data.application;
}

export async function scheduleInterview(id, schedule) {
  return updateEmployerApplication(id, "Interview", {
    interview_date: schedule.date,
    interview_time: schedule.time,
    interview_notes: schedule.notes || "",
  });
}

export async function fetchApplicantApplications(params = {}) {
  const { data } = await api.get("/applicant/applications", { params });
  return data;
}

export async function updateApplicationSharing(id, share) {
  return (await api.patch(`/applicant/applications/${id}`, {
    share_accommodations: share,
  })).data;
}

export async function downloadApplicantResume(id) {
  const { data, headers } = await api.get(`/employer/applications/${id}/resume`, {
    responseType: "blob",
  });
  const url = URL.createObjectURL(data);
  const link = document.createElement("a");
  link.href = url;
  const extension = headers["content-disposition"]?.match(/filename="?resume\.(pdf|docx?)\b/i)?.[1]
    || (data.type.includes("pdf") ? "pdf" : data.type.includes("wordprocessingml") ? "docx" : "doc");
  link.download = `resume.${extension}`;
  link.click();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
}
