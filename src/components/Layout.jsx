import { Outlet } from "react-router-dom";
import { useUi } from "../context/UiContext.jsx";
import AccessibilityBar from "./AccessibilityBar.jsx";
import Navbar from "./Navbar.jsx";
import Footer from "./Footer.jsx";
import Toast from "./Toast.jsx";
import Modal from "./Modal.jsx";

function Layout() {
  const { announcement } = useUi();

  return (
    <div className="min-h-screen flex flex-col bg-white text-brand-dark antialiased selection:bg-brand-yellow selection:text-brand-dark">
      <div className="sr-only" aria-live="polite">
        {announcement}
      </div>
      <AccessibilityBar />
      <Navbar />
      <div className="flex-1">
        <Outlet />
      </div>
      <Footer />
      <Toast />
      <Modal />
    </div>
  );
}

export default Layout;
