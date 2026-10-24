import api from "./api.js";

export async function fetchOverview() {
  return (await api.get("/admin/overview")).data.stats;
}
export async function fetchAdminEmployers(params = {}) {
  return (await api.get("/admin/employers", { params })).data;
}
export async function fetchAdminJobs(params = {}) {
  return (await api.get("/admin/jobs", { params })).data;
}
export async function fetchAdminUsers(params = {}) {
  return (await api.get("/admin/users", { params })).data;
}
export async function fetchAdminReports(params = {}) {
  return (await api.get("/admin/reports", { params })).data;
}
export async function fetchAuditLogs(params = {}) {
  return (await api.get("/admin/audit-logs", { params })).data;
}
const records = {
  employer: { path: "employers", key: "employer", action: "/verification" },
  job: { path: "jobs", key: "job", action: "/moderation" },
  user: { path: "users", key: "user", action: "/status" },
  report: { path: "reports", key: "report", action: "" },
};
export async function fetchAdminRecord(kind, id) {
  const resource = records[kind];
  return (await api.get(`/admin/${resource.path}/${id}`)).data[resource.key];
}
export async function decideAdminRecord(kind, record, payload) {
  const resource = records[kind];
  return (await api.patch(`/admin/${resource.path}/${record.id}${resource.action}`, {
    ...payload, expected_version: record.reviewVersion,
  })).data[resource.key];
}
