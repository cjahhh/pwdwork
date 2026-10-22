import { apiErrorMessage } from "../services/api.js";
import { useState } from "react";
import { Link, NavLink, useNavigate } from "react-router-dom";
import { useAuth } from "../context/AuthContext.jsx";
import { useUi } from "../context/UiContext.jsx";

const navLinkClass = ({ isActive }) =>
  isActive
    ? "text-brand-blue font-extrabold border-b-2 border-brand-blue pb-1"
    : "hover:text-brand-blue transition pb-1";

function Navbar() {
  const [open, setOpen] = useState(false);
  const { user, logout } = useAuth();
  const { showToast } = useUi();
  const navigate = useNavigate();
  const navigation =
    user?.role === "employer"
      ? [
          { label: "Company Profile", to: "/employer/profile" },
          { label: "Job Listings", to: "/employer/listings" },
          { label: "Applicants", to: "/employer/applicants" },
        ]
      : user?.role === "applicant"
        ? [
            { label: "Find Jobs", to: "/jobs" },
            { label: "Profile", to: "/applicant/profile" },
            { label: "Applications", to: "/applicant/applications" },
            { label: "Saved Jobs", to: "/applicant/saved-jobs" },
          ]
        : [
            { label: "Home", to: "/", end: true },
            { label: "Find Jobs", to: "/jobs" },
            { label: "For Employers", to: "/employers" },
            { label: "About", to: "/about" },
          ];

  async function onLogout() {
    try {
      await logout();
    } catch (e) {
      showToast("Logout failed", apiErrorMessage(e));
      return;
    }
    setOpen(false);
    showToast("Logged out", "Your session has ended.");
    navigate("/");
  }

  return (
    <header className="sticky top-0 z-40 bg-white/95 backdrop-blur-md border-b border-gray-200 shadow-sm">
      <nav
        className="max-w-[1440px] mx-auto px-6 lg:px-12 py-4 flex items-center justify-between gap-6"
        aria-label="Main Navigation"
      >
        <Link
          to="/"
          className="flex items-center gap-3 group"
          aria-label="PWDWork Homepage"
        >
          <div className="w-12 h-12 rounded-2xl bg-brand-blue flex items-center justify-center text-white shadow-md group-hover:scale-105 transition-transform duration-200">
            <svg
              className="w-7 h-7 text-brand-yellow"
              fill="currentColor"
              viewBox="0 0 24 24"
              aria-hidden="true"
            >
              <path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z" />
            </svg>
          </div>
          <div>
            <span className="text-2xl font-black tracking-tight text-brand-blue block leading-none">
              PWDWork
            </span>
            <span className="text-xs font-semibold text-brand-teal tracking-wide block mt-1">
              Inclusive Jobs & Careers
            </span>
          </div>
        </Link>

        <ul
          className="hidden lg:flex items-center gap-8 font-bold text-base text-gray-700"
          role="list"
        >
          {navigation.map((item) => (
            <li key={item.to}>
              <NavLink to={item.to} end={item.end} className={navLinkClass}>
                {item.label}
              </NavLink>
            </li>
          ))}
        </ul>

        <div className="flex items-center gap-3.5">
          {user ? (
            <>
              <button
                type="button"
                onClick={onLogout}
                className="hidden sm:block bg-brand-blue hover:bg-brand-blue-dark text-white font-extrabold text-base px-6 py-2.5 rounded-2xl shadow-soft-lg"
              >
                Log out
              </button>
            </>
          ) : (
            <>
              <Link
                to="/login"
                className="hidden xl:inline-flex border-2 border-brand-blue text-brand-blue hover:bg-blue-50 font-extrabold text-base px-5 py-2.5 rounded-2xl transition duration-150"
              >
                Log In
              </Link>
              <Link
                to="/register"
                className="bg-brand-blue hover:bg-brand-blue-dark text-white font-extrabold text-base px-6 py-2.5 rounded-2xl shadow-soft-lg transition duration-150 hidden sm:flex items-center gap-2"
              >
                <span>Sign Up</span>
                <svg
                  className="w-4 h-4"
                  fill="none"
                  stroke="currentColor"
                  viewBox="0 0 24 24"
                  aria-hidden="true"
                >
                  <path
                    strokeLinecap="round"
                    strokeLinejoin="round"
                    strokeWidth="2.5"
                    d="M14 5l7 7m0 0l-7 7m7-7H3"
                  />
                </svg>
              </Link>
            </>
          )}
          <button
            type="button"
            className="lg:hidden shrink-0 w-11 h-11 rounded-xl border-2 border-brand-blue text-brand-blue font-black"
            aria-expanded={open}
            aria-controls="mobile-nav"
            onClick={() => setOpen((current) => !current)}
          >
            {open ? "✕" : "☰"}
            <span className="sr-only">Toggle menu</span>
          </button>
        </div>
      </nav>

      {open ? (
        <div
          id="mobile-nav"
          className="lg:hidden border-t border-gray-200 bg-white px-6 py-4 space-y-3"
        >
          {navigation.map((item) => (
            <NavLink
              key={item.to}
              to={item.to}
              end={item.end}
              className="block font-bold text-brand-dark"
              onClick={() => setOpen(false)}
            >
              {item.label}
            </NavLink>
          ))}
          {user ? (
            <>
              <button
                type="button"
                className="block font-bold text-brand-dark"
                onClick={onLogout}
              >
                Log out
              </button>
            </>
          ) : (
            <>
              <NavLink
                to="/login"
                className="block font-bold text-brand-blue"
                onClick={() => setOpen(false)}
              >
                Log In
              </NavLink>
              <NavLink
                to="/register"
                className="block font-bold text-brand-blue"
                onClick={() => setOpen(false)}
              >
                Sign Up
              </NavLink>
            </>
          )}
        </div>
      ) : null}
    </header>
  );
}

export default Navbar;
