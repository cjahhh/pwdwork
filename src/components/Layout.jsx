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
  const { error, retry, notice } = useAuth();
  const { pathname } = useLocation();
  useEffect(() => {
    const target = document.getElementById("main-content");
    if (target) {
      target.focus({ preventScroll: true });
      window.scrollTo(0, 0);
    }
  }, [pathname]);

  return (
    <div className="min-h-screen flex flex-col bg-white text-brand-dark antialiased selection:bg-brand-yellow selection:text-brand-dark">
      <div className="sr-only" aria-live="polite">
        {announcement}
      </div>
      <a className="skip-link" href="#main-content">
        Skip to content
      </a>
      <AccessibilityBar />
      <Navbar />
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
      <Toast />
      <Modal />
    </div>
  );
}

export default Layout;
