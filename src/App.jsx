import { Routes, Route } from "react-router-dom";
import Layout from "./components/Layout.jsx";
import ProtectedRoute from "./components/ProtectedRoute.jsx";
import RecommendationsPage from "./pages/RecommendationsPage.jsx";
import HomePage from "./pages/HomePage.jsx";
import FindJobsPage from "./pages/FindJobsPage.jsx";
const JobDetailsPage = lazy(() => import("./pages/JobDetailsPage.jsx"));
const ForEmployersPage = lazy(() => import("./pages/ForEmployersPage.jsx"));
const AboutPage = lazy(() => import("./pages/AboutPage.jsx"));
const LoginPage = lazy(() => import("./pages/LoginPage.jsx"));
const RegisterPage = lazy(() => import("./pages/RegisterPage.jsx"));
const VerifyEmailPage = lazy(() => import("./pages/VerifyEmailPage.jsx"));
const PasswordRecoveryPage = lazy(() => import("./pages/PasswordRecoveryPage.jsx"));
const ForgotPasswordPage = lazy(() => import("./pages/ForgotPasswordPage.jsx"));
const AccommodationRequestsPage = lazy(() => import("./pages/AccommodationRequestsPage.jsx"));
const ApplicantProfilePage = lazy(() => import("./pages/ApplicantProfilePage.jsx"));
const ApplicantApplicationsEnhancedPage = lazy(() => import("./pages/ApplicantApplicationsEnhancedPage.jsx"));
const ApplicantSavedJobsPage = lazy(() => import("./pages/ApplicantSavedJobsPage.jsx"));
const EmployerProfilePage = lazy(() => import("./pages/EmployerProfilePage.jsx"));
const EmployerListingsEnhancedPage = lazy(() => import("./pages/EmployerListingsEnhancedPage.jsx"));
const EmployerApplicantsEnhancedPage = lazy(() => import("./pages/EmployerApplicantsEnhancedPage.jsx"));
const AdminDashboardPage = lazy(() => import("./pages/AdminDashboardPage.jsx"));
const AdminLayout = lazy(() => import("./pages/admin/AdminLayout.jsx"));
const AdminQueuePage = lazy(() => import("./pages/admin/AdminQueuePage.jsx"));
const AdminAuditPage = lazy(() => import("./pages/admin/AdminAuditPage.jsx"));
const ReportsPage = lazy(() => import("./pages/ReportsPage.jsx"));
const NotificationsPage = lazy(() => import("./pages/NotificationsPage.jsx"));
const NotFoundPage = lazy(() => import("./pages/NotFoundPage.jsx"));
import { lazy, Suspense } from 'react';
const ResumesPage = lazy(() => import('./pages/ResumesPage.jsx'));
const ApplicantDashboardPage = lazy(() => import('./pages/ApplicantDashboardPage.jsx'));
const EmployerDashboardPage = lazy(() => import('./pages/EmployerDashboardPage.jsx'));

/**
 * App.jsx — route table for PWDWork.
 * Public pages are open. Dashboard routes enforce authenticated role checks.
 */
function App() {
  return (
    <Suspense fallback={<main className="page-shell"><p role="status">Loading page…</p></main>}><Routes>
      <Route element={<Layout />}>
        <Route path="/" element={<HomePage />} />
        <Route path="/jobs" element={<FindJobsPage />} />
        <Route path="/jobs/:id" element={<JobDetailsPage />} />
        <Route path="/employers" element={<ForEmployersPage />} />
        <Route path="/about" element={<AboutPage />} />
        <Route path="/login" element={<LoginPage />} />
        <Route path="/register" element={<RegisterPage />} />
        <Route path="/verify-email" element={<VerifyEmailPage />} />
        <Route path="/reset-password" element={<PasswordRecoveryPage reset />} />
        <Route path="/forgot-password" element={<ForgotPasswordPage />} />

        <Route
          path="/applicant"
          element={
            <ProtectedRoute allowedRoles={["applicant"]}>
              <ApplicantDashboardPage />
            </ProtectedRoute>
          }
        />
        <Route path="/applicant/recommendations" element={<ProtectedRoute allowedRoles={["applicant"]}><RecommendationsPage /></ProtectedRoute>} />
        <Route path="/applicant/resumes" element={<ProtectedRoute allowedRoles={["applicant"]}><Suspense fallback={<p role="status">Loading resume builder…</p>}><ResumesPage /></Suspense></ProtectedRoute>} />
        <Route path="/applicant/accommodation-requests/:id?" element={<ProtectedRoute allowedRoles={["applicant"]}><AccommodationRequestsPage /></ProtectedRoute>} />
        <Route path="/applicant/profile" element={<ProtectedRoute allowedRoles={["applicant"]}><ApplicantProfilePage /></ProtectedRoute>} />
        <Route path="/applicant/applications" element={<ProtectedRoute allowedRoles={["applicant"]}><ApplicantApplicationsEnhancedPage /></ProtectedRoute>} />
        <Route path="/applicant/saved-jobs" element={<ProtectedRoute allowedRoles={["applicant"]}><ApplicantSavedJobsPage /></ProtectedRoute>} />
        <Route
          path="/employer"
          element={
            <ProtectedRoute allowedRoles={["employer"]}>
              <EmployerDashboardPage />
            </ProtectedRoute>
          }
        />
        <Route path="/employer/accommodation-requests/:id?" element={<ProtectedRoute allowedRoles={["employer"]}><AccommodationRequestsPage /></ProtectedRoute>} />
        <Route path="/employer/profile" element={<ProtectedRoute allowedRoles={["employer"]}><EmployerProfilePage /></ProtectedRoute>} />
        <Route path="/employer/listings" element={<ProtectedRoute allowedRoles={["employer"]}><EmployerListingsEnhancedPage /></ProtectedRoute>} />
        <Route path="/employer/applicants" element={<ProtectedRoute allowedRoles={["employer"]}><EmployerApplicantsEnhancedPage /></ProtectedRoute>} />
        <Route
          path="/admin"
          element={
            <ProtectedRoute allowedRoles={["admin"]}>
              <AdminLayout />
            </ProtectedRoute>
          }
        >
          <Route index element={<AdminDashboardPage />} />
          <Route path="employers" element={<AdminQueuePage key="employer" kind="employer" />} />
          <Route path="jobs" element={<AdminQueuePage key="job" kind="job" />} />
          <Route path="users" element={<AdminQueuePage key="user" kind="user" />} />
          <Route path="reports" element={<AdminQueuePage key="report" kind="report" />} />
          <Route path="audit" element={<AdminAuditPage />} />
        </Route>
        <Route path="/reports" element={<ProtectedRoute allowedRoles={["applicant", "employer"]}><ReportsPage /></ProtectedRoute>} />
        <Route path="/notifications" element={<ProtectedRoute allowedRoles={["applicant", "employer", "admin"]}><NotificationsPage /></ProtectedRoute>} />

        <Route path="*" element={<NotFoundPage />} />
      </Route>
    </Routes></Suspense>
  );
}

export default App;
