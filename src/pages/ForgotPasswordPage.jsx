import { useState } from "react";
import { Link } from "react-router-dom";
import { useUi } from "../context/UiContext.jsx";

function ForgotPasswordPage() {
  const { showToast } = useUi();
  const [email, setEmail] = useState("");

  function onSubmit(event) {
    event.preventDefault();
    showToast("Reset preview", "No real emails are sent in this prototype.");
  }

  return (
    <main className="bg-brand-gray-bg py-16">
      <div className="max-w-lg mx-auto px-6">
        <div className="bg-white rounded-3xl p-8 shadow-soft-xl border-2 border-brand-blue space-y-6">
          <div>
            <h1 className="text-3xl font-black text-brand-dark">Forgot Password</h1>
            <p className="text-gray-600 mt-2">Enter your email to preview the reset flow. No messages are sent.</p>
          </div>
          <form className="space-y-4" onSubmit={onSubmit}>
            <div>
              <label htmlFor="reset-email" className="block text-xs font-extrabold uppercase tracking-wider text-brand-teal mb-1">
                Email
              </label>
              <input
                id="reset-email"
                type="email"
                required
                value={email}
                onChange={(event) => setEmail(event.target.value)}
                className="w-full px-4 py-3 bg-gray-50 border-2 border-gray-200 rounded-xl font-medium text-brand-dark focus:bg-white focus:border-brand-blue"
              />
            </div>
            <button type="submit" className="w-full bg-brand-blue text-white font-black py-3.5 rounded-2xl">
              Send reset link
            </button>
          </form>
          <Link to="/login" className="text-brand-teal font-bold hover:underline">
            Back to login
          </Link>
        </div>
      </div>
    </main>
  );
}

export default ForgotPasswordPage;
