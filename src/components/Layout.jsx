import { Outlet } from "react-router-dom";
import Navbar from "./Navbar.jsx";
import Footer from "./Footer.jsx";
import styles from "./Layout.module.css";

/**
 * Shared page chrome: header (navbar), main content, footer.
 * Child routes render inside <Outlet /> (React Router).
 */
function Layout() {
  return (
    <div className={styles.shell}>
      <Navbar />
      <div className={styles.content}>
        <Outlet />
      </div>
      <Footer />
    </div>
  );
}

export default Layout;
