import { useParams } from "react-router-dom";
import PlaceholderPage from "../components/PlaceholderPage.jsx";

function JobDetailsPage() {
  const { id } = useParams();

  return (
    <PlaceholderPage
      title="Job Details"
      description={`Job details placeholder for listing id ${id}. Role overview, accommodations, match badge, and Apply Now will be added in Phases 4 and 6.`}
    />
  );
}

export default JobDetailsPage;
