import { NavLink, Outlet } from "react-router-dom";

export default function AdminLayout() {
  return (
    <main className="page-shell admin-shell">
      <p className="font-bold text-brand-teal">PWDWork administration</p>
      <nav aria-label="Administration sections" className="admin-nav">
        {[["", "Overview"], ["employers", "Employer verification"], ["jobs", "Job moderation"],
          ["users", "User management"], ["reports", "Reports and complaints"], ["audit", "Audit logs"]].map(([path, label]) => (
          <NavLink key={path} end to={`/admin${path ? "/" + path : ""}`} className={({ isActive }) => isActive ? "admin-nav-active" : ""}>{label}</NavLink>
        ))}
      </nav>
      <Outlet />
    </main>
  );
}
