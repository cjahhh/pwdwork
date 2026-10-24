import { Navigate, Routes, Route } from "react-router-dom";
import Layout from "./components/Layout.jsx";
import ProtectedRoute from "./components/ProtectedRoute.jsx";
import RecommendationsPage from "./pages/RecommendationsPage.jsx";
import HomePage from "./pages/HomePage.jsx";
import FindJobsPage from "./pages/FindJobsPage.jsx";
import JobDetailsPage from "./pages/JobDetailsPage.jsx";
import ForEmployersPage from "./pages/ForEmployersPage.jsx";
import AboutPage from "./pages/AboutPage.jsx";
import LoginPage from "./pages/LoginPage.jsx";
import RegisterPage from "./pages/RegisterPage.jsx";
import PasswordRecoveryPage from "./pages/PasswordRecoveryPage.jsx";
import ForgotPasswordPage from "./pages/ForgotPasswordPage.jsx";
import ApplicantProfilePage from "./pages/ApplicantProfilePage.jsx";
import ApplicantApplicationsEnhancedPage from "./pages/ApplicantApplicationsEnhancedPage.jsx";
import ApplicantSavedJobsPage from "./pages/ApplicantSavedJobsPage.jsx";
import EmployerProfilePage from "./pages/EmployerProfilePage.jsx";
import EmployerListingsEnhancedPage from "./pages/EmployerListingsEnhancedPage.jsx";
import EmployerApplicantsEnhancedPage from "./pages/EmployerApplicantsEnhancedPage.jsx";
import AdminDashboardPage from "./pages/AdminDashboardPage.jsx";
import AdminLayout from "./pages/admin/AdminLayout.jsx";
import AdminQueuePage from "./pages/admin/AdminQueuePage.jsx";
import AdminAuditPage from "./pages/admin/AdminAuditPage.jsx";
import ReportsPage from "./pages/ReportsPage.jsx";
import NotificationsPage from "./pages/NotificationsPage.jsx";
import NotFoundPage from "./pages/NotFoundPage.jsx";

/**
 * App.jsx — route table for PWDWork.
 * Public pages are open. Dashboard routes enforce authenticated role checks.
 */
function App() {
  return (
    <Routes>
      <Route element={<Layout />}>
        <Route path="/" element={<HomePage />} />
        <Route path="/jobs" element={<FindJobsPage />} />
        <Route path="/jobs/:id" element={<JobDetailsPage />} />
        <Route path="/employers" element={<ForEmployersPage />} />
        <Route path="/about" element={<AboutPage />} />
        <Route path="/login" element={<LoginPage />} />
        <Route path="/register" element={<RegisterPage />} />
        <Route path="/reset-password" element={<PasswordRecoveryPage reset />} />
        <Route path="/forgot-password" element={<ForgotPasswordPage />} />

        <Route
          path="/applicant"
          element={
            <ProtectedRoute allowedRoles={["applicant"]}>
              <Navigate to="/jobs" replace />
            </ProtectedRoute>
          }
        />
        <Route path="/applicant/recommendations" element={<ProtectedRoute allowedRoles={["applicant"]}><RecommendationsPage /></ProtectedRoute>} />
        <Route path="/applicant/profile" element={<ProtectedRoute allowedRoles={["applicant"]}><ApplicantProfilePage /></ProtectedRoute>} />
        <Route path="/applicant/applications" element={<ProtectedRoute allowedRoles={["applicant"]}><ApplicantApplicationsEnhancedPage /></ProtectedRoute>} />
        <Route path="/applicant/saved-jobs" element={<ProtectedRoute allowedRoles={["applicant"]}><ApplicantSavedJobsPage /></ProtectedRoute>} />
        <Route
          path="/employer"
          element={
            <ProtectedRoute allowedRoles={["employer"]}>
              <Navigate to="/employer/listings" replace />
            </ProtectedRoute>
          }
        />
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
    </Routes>
  );
}

export default App;
