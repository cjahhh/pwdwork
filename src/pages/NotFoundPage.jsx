import { Link } from "react-router-dom";

function NotFoundPage() {
  return (
    <main className="bg-brand-gray-bg py-24">
      <div className="max-w-xl mx-auto px-6 text-center space-y-4">
        <h1 className="text-4xl font-black text-brand-dark">Page not found</h1>
        <p className="text-gray-600">That address is not a PWDWork page. Use the menu or return to Home.</p>
        <Link
          to="/"
          className="inline-flex bg-brand-blue hover:bg-brand-blue-dark text-white font-extrabold px-6 py-3 rounded-2xl"
        >
          Return to Home
        </Link>
      </div>
    </main>
  );
}

export default NotFoundPage;
