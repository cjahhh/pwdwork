import { NavLink } from "react-router-dom";
import styles from "./Navbar.module.css";

/**
 * Shared site navigation. After login (Phase 3–5) the Log In link
 * will become a user menu based on AuthContext.
 */
function Navbar() {
  return (
    <header className={styles.header}>
      <div className={styles.inner}>
        <NavLink to="/" className={styles.logo}>
          PWDWork
        </NavLink>
        <nav aria-label="Main">
          <ul className={styles.list}>
            <li>
              <NavLink
                to="/"
                className={({ isActive }) =>
                  isActive ? styles.active : styles.link
                }
                end
              >
                Home
              </NavLink>
            </li>
            <li>
              <NavLink
                to="/jobs"
                className={({ isActive }) =>
                  isActive ? styles.active : styles.link
                }
              >
                Find Jobs
              </NavLink>
            </li>
            <li>
              <NavLink
                to="/employers"
                className={({ isActive }) =>
                  isActive ? styles.active : styles.link
                }
              >
                For Employers
              </NavLink>
            </li>
            <li>
              <NavLink
                to="/about"
                className={({ isActive }) =>
                  isActive ? styles.active : styles.link
                }
              >
                About
              </NavLink>
            </li>
            <li>
              <NavLink
                to="/login"
                className={({ isActive }) =>
                  isActive ? styles.active : styles.link
                }
              >
                Log In
              </NavLink>
            </li>
          </ul>
        </nav>
      </div>
    </header>
  );
}

export default Navbar;
