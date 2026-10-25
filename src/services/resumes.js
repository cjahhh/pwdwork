import api from './api.js';

export async function downloadBuiltResume(path, title = 'resume') {
  const { data } = await api.get(path, { responseType: 'blob' });
  const url = URL.createObjectURL(data);
  const link = document.createElement('a');
  link.href = url;
  link.download = `${title.replace(/[^a-zA-Z0-9_-]/g, '-').slice(0, 100) || 'resume'}.pdf`;
  link.click();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
}

export async function resumePreview(path) {
  return (await api.get(path, { responseType: 'text' })).data;
}
