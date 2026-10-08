/**
 * Role-based route guard.
 *
 * Phase 1: renders children so routes can be visited while we build.
 * Phase 3: will read AuthContext, redirect guests to /login, and
 * send the wrong role to an unauthorized page.
 */
function ProtectedRoute({ children, allowedRoles }) {
  // allowedRoles is unused until AuthContext exists (Phase 3).
  void allowedRoles;
  return children;
}

export default ProtectedRoute;
