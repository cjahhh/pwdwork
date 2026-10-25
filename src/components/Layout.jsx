import { useEffect } from "react";
import { Outlet, useLocation } from "react-router-dom";
import { useAuth } from "../context/AuthContext.jsx";
import { useUi } from "../context/UiContext.jsx";
import AccessibilityBar from "./AccessibilityBar.jsx";
import Navbar from "./Navbar.jsx";
import Footer from "./Footer.jsx";
import Toast from "./Toast.jsx";
import Modal from "./Modal.jsx";

function Layout() {
  const { announcement } = useUi();
  const { user, error, retry, notice } = useAuth();
  const { pathname } = useLocation();
  const application = Boolean(user && (/^\/(applicant|employer|admin)(\/|$)/.test(pathname) || ['/notifications', '/reports'].includes(pathname) || (user.role === 'applicant' && /^\/jobs(\/|$)/.test(pathname))));
  useEffect(() => {
    const target = document.getElementById("main-content");
    if (target) {
      target.focus({ preventScroll: true });
      window.scrollTo(0, 0);
    }
  }, [pathname]);

  return (
    <div className={`product-root ${application ? 'workspace-mode' : 'public-mode'}`}>
      <div className="sr-only" aria-live="polite">
        {announcement}
      </div>
      <a className="skip-link" href="#main-content">
        Skip to content
      </a>
      <AccessibilityBar />
      <Navbar application={application} />
      <div className="workspace-body">
      {notice && (
        <p role="status" className="panel">
          {notice}
        </p>
      )}
      {error && (
        <div role="alert" className="panel">
          <p>{error}</p>
          <button onClick={retry}>Retry account loading</button>
        </div>
      )}
      <div id="main-content" tabIndex={-1} className="flex-1">
        <Outlet />
      </div>
      <Footer />
      </div>
      <Toast />
      <Modal />
    </div>
  );
}

export default Layout;
