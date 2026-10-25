import { Navigate } from "react-router-dom";
import { useAuth } from "../context/AuthContext.jsx";
import { dashboardPath } from "../services/auth.js";

function ProtectedRoute({ children, allowedRoles }) {
  const { user, ready, error, retry } = useAuth();

  if (!ready) {
    return (
      <main className="bg-brand-gray-bg py-16">
        <p role="status" className="text-center font-bold text-brand-blue">Loading account...</p>
      </main>
    );
  }

  if (error) return <main className="page-shell"><p role="alert">{error}</p><button onClick={retry}>Retry account loading</button></main>;

  if (!user) {
    return <Navigate to="/login" replace />;
  }

  if (allowedRoles?.length && !allowedRoles.includes(user.role)) {
    return <Navigate to={dashboardPath(user.role)} replace />;
  }

  return children;
}

export default ProtectedRoute;
