All data access belongs here (jobService, authService, applicationService, and so on).
Phase 1 only has api.js (Axios, unused until Laravel exists).
Phase 2 will add mock service functions that return Promises.
Components import services only — never mock data files.
