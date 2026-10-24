import api from './api.js';

export async function fetchRequestCatalog() {
  return (await api.get('/accommodation-requests/catalog')).data;
}
export async function fetchAccommodationRequests(params = {}) {
  return (await api.get('/accommodation-requests', { params })).data;
}
export async function fetchAccommodationRequest(id) {
  return (await api.get(`/accommodation-requests/${id}`)).data.request;
}
export async function createAccommodationRequest(payload) {
  return (await api.post('/accommodation-requests', payload)).data.request;
}
export async function actOnAccommodationRequest(id, payload) {
  return (await api.post(`/accommodation-requests/${id}/actions`, payload)).data.request;
}
export async function fetchRequestMessages(id, params) {
  return (await api.get(`/accommodation-requests/${id}/messages`, { params })).data;
}
export async function fetchRequestEvents(id, params) {
  return (await api.get(`/accommodation-requests/${id}/events`, { params })).data;
}
