import { Link } from "react-router-dom";
import { useUi } from "../context/UiContext.jsx";

function Footer() {
  const { openModal } = useUi();

  function preview(title, text) {
    openModal({
      title,
      content: (
        <div className="space-y-4">
          <p className="text-gray-700 text-base leading-relaxed">{text}</p>
          <div className="p-4 bg-gray-100 rounded-2xl border border-gray-300 text-sm font-medium text-gray-600">
            All interface elements are designed to WCAG 2.1 AAA accessibility standards.
          </div>
        </div>
      ),
    });
  }

  return (
    <footer className="bg-brand-blue-dark text-white pt-16 pb-12">
      <div className="max-w-[1440px] mx-auto px-6 lg:px-12">
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-10 pb-12 border-b border-gray-800">
          <div className="lg:col-span-2 space-y-4">
            <div className="flex items-center gap-3">
              <div className="w-10 h-10 rounded-xl bg-brand-blue border border-white/20 flex items-center justify-center text-white">
                <svg className="w-6 h-6 text-brand-yellow" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                  <path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z" />
                </svg>
              </div>
              <span className="text-2xl font-black tracking-tight text-white">PWDWork</span>
            </div>
            <p className="text-gray-300 text-base leading-relaxed max-w-sm">
              PWDWork is a social-impact employment platform built specifically to bridge persons with disabilities to
              accessible, empathetic, and inclusive hiring organizations.
            </p>
            <div className="pt-2 text-sm text-gray-400 font-medium">
              Need Help or Accommodations?{" "}
              <a href="mailto:support@pwdwork.org" className="text-brand-yellow underline">
                support@pwdwork.org
              </a>
            </div>
          </div>

          <div className="space-y-3">
            <h3 className="font-extrabold text-lg text-white border-b border-gray-800 pb-2">Quick Links</h3>
            <ul className="space-y-2 text-base text-gray-300">
              <li>
                <Link to="/" className="hover:text-brand-yellow transition">
                  Home
                </Link>
              </li>
              <li>
                <Link to="/jobs" className="hover:text-brand-yellow transition">
                  Find Jobs
                </Link>
              </li>
              <li>
                <Link to="/employers" className="hover:text-brand-yellow transition">
                  For Employers
                </Link>
              </li>
              <li>
                <Link to="/about" className="hover:text-brand-yellow transition">
                  About PWDWork
                </Link>
              </li>
              <li>
                <button
                  type="button"
                  className="hover:text-brand-yellow transition"
                  onClick={() =>
                    preview("Accessibility Statement", "Read our WCAG 2.1 AAA Accessibility Compliance Policy.")
                  }
                >
                  Accessibility Statement
                </button>
              </li>
            </ul>
          </div>

          <div className="space-y-3">
            <h3 className="font-extrabold text-lg text-white border-b border-gray-800 pb-2">Employers</h3>
            <ul className="space-y-2 text-base text-gray-300">
              <li>
                <Link to="/register?role=employer" className="hover:text-brand-yellow transition">
                  Post a Job Listing
                </Link>
              </li>
              <li>
                <button
                  type="button"
                  className="hover:text-brand-yellow transition"
                  onClick={() =>
                    preview("Workplace Accommodations Guide", "Guidelines for creating accessible workplaces.")
                  }
                >
                  Accommodation Toolkit
                </button>
              </li>
              <li>
                <button
                  type="button"
                  className="hover:text-brand-yellow transition"
                  onClick={() =>
                    preview("Inclusive Hiring Standards", "Best practices for accessible interviewing.")
                  }
                >
                  Hiring Standards
                </button>
              </li>
              <li>
                <Link to="/employer" className="hover:text-brand-yellow transition">
                  Employer Dashboard
                </Link>
              </li>
            </ul>
          </div>

          <div className="space-y-3">
            <h3 className="font-extrabold text-lg text-white border-b border-gray-800 pb-2">Contact & Support</h3>
            <div className="space-y-2 text-base text-gray-300">
              <p className="flex items-center gap-2">
                <span aria-hidden="true">📧</span> support@pwdwork.org
              </p>
              <p className="flex items-center gap-2">
                <span aria-hidden="true">📞</span> +1 (800) 555-PWD1 (TTY)
              </p>
              <p className="flex items-center gap-2">
                <span aria-hidden="true">🤟</span> Sign Language Hotline Available
              </p>
              <p className="text-xs text-gray-400 mt-2">Hours: Mon - Fri, 8:00 AM - 6:00 PM</p>
            </div>
          </div>
        </div>

        <div className="py-6 border-b border-gray-800 flex flex-col md:flex-row items-center justify-between gap-4">
          <div className="flex items-center gap-3">
            <span className="bg-brand-teal text-white font-extrabold text-xs px-3 py-1 rounded-full uppercase tracking-wider">
              UN SDG Alignment
            </span>
            <p className="text-sm font-semibold text-gray-200">
              Supporting <strong className="text-brand-yellow">SDG 8: Decent Work and Economic Growth</strong> &{" "}
              <strong className="text-brand-yellow">SDG 10: Reduced Inequalities</strong>.
            </p>
          </div>
          <div className="flex items-center gap-2 text-xs font-bold text-gray-300">
            <span className="border border-gray-700 px-2.5 py-1 rounded bg-gray-900">SDG 8 Certified</span>
            <span className="border border-gray-700 px-2.5 py-1 rounded bg-gray-900">SDG 10 Certified</span>
          </div>
        </div>

        <div className="pt-8 flex flex-col sm:flex-row items-center justify-between text-sm text-gray-400 gap-4">
          <p>© 2026 PWDWork Platform. All Rights Reserved. Built with WCAG 2.1 AAA Accessibility Standards.</p>
          <div className="flex gap-6">
            <button type="button" className="hover:underline" onClick={() => preview("Privacy Policy", "Privacy Policy Details")}>
              Privacy Policy
            </button>
            <button
              type="button"
              className="hover:underline"
              onClick={() => preview("Terms of Service", "Terms of Service Details")}
            >
              Terms of Service
            </button>
          </div>
        </div>
      </div>
    </footer>
  );
}

export default Footer;
