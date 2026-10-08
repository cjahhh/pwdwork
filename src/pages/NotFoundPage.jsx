import { Link } from "react-router-dom";
import PlaceholderPage from "../components/PlaceholderPage.jsx";

function NotFoundPage() {
  return (
    <PlaceholderPage
      title="Page not found"
      description="That address is not a PWDWork page. Use the menu or return to Home."
    >
      <p>
        <Link to="/">Return to Home</Link>
      </p>
    </PlaceholderPage>
  );
}

export default NotFoundPage;
