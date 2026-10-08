import styles from "./PlaceholderPage.module.css";

/**
 * Temporary page body used until real screens are built in later phases.
 */
function PlaceholderPage({ title, description, children }) {
  return (
    <main>
      <h1 className={styles.title}>{title}</h1>
      <p className={styles.text}>{description}</p>
      {children}
    </main>
  );
}

export default PlaceholderPage;
