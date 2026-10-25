import { useState } from "react";
import { Link, useLocation, useNavigate, useSearchParams } from "react-router-dom";
import api, { apiErrorMessage, prepareCsrf } from "../services/api.js";
import AuthLayout from "../components/AuthLayout.jsx";
export default function PasswordRecoveryPage({ reset = false }) {
  const [params] = useSearchParams();
  const { hash } = useLocation();
  const navigate = useNavigate();
  const fragment = new URLSearchParams(hash.slice(1));
  const [email, setEmail] = useState(fragment.get("email") || params.get("email") || ""),
    [password, setPassword] = useState(""),
    [confirmation, setConfirmation] = useState(""),
    [busy, setBusy] = useState(false),
    [error, setError] = useState(""),
    [message, setMessage] = useState(""),
    [completed, setCompleted] = useState(false);
  // New emails keep secrets in a fragment; existing query links still work.
  const token = fragment.get("token") || params.get("token");
  async function submit(e) {
    e.preventDefault();
    setError("");
    setMessage("");
    if (reset && password !== confirmation) {
      setError("Passwords must match.");
      return;
    }
    setBusy(true);
    try {
      await prepareCsrf();
      const { data } = await api.post(
        reset ? "/reset-password" : "/forgot-password",
        reset
          ? { email, password, password_confirmation: confirmation, token }
          : { email },
      );
      setMessage(data.message);
      setPassword("");
      setConfirmation("");
      if (reset) {
        setCompleted(true);
        navigate('/reset-password', { replace: true });
      }
    } catch (e) {
      setError(apiErrorMessage(e));
    } finally {
      setBusy(false);
    }
  }
  return (
    <AuthLayout><div className="auth-card page-shell">
      <h1>{reset ? "Reset your password" : "Forgot password"}</h1>
      <form className="panel space-y-5" onSubmit={submit} aria-busy={busy}>
        <p>
          {reset
            ? "Choose a password with at least 8 characters. Reset links expire after 60 minutes."
            : "Enter your account email to request a secure reset link."}
        </p>
        {error && (
          <p role="alert" id="recovery-error">
            {error}
          </p>
        )}
        <p role="status">{reset && completed && token ? '' : message}</p>
        {completed && !token ? <p>You can now sign in with your new password.</p> : reset && !token ? (
          <p role="alert">The reset token is missing. Request a new link.</p>
        ) : (
          <>
            <label className="block">
              <span className="form-label">Email</span>
              <input
                className="form-input"
                type="email"
                autoComplete="email"
                required
                value={email}
                onChange={(e) => setEmail(e.target.value)}
              />
            </label>
            {reset && (
              <>
                <label className="block">
                  <span className="form-label">New password</span>
                  <input
                    className="form-input"
                    type="password"
                    autoComplete="new-password"
                    required
                    minLength={8}
                    value={password}
                    onChange={(e) => setPassword(e.target.value)}
                  />
                </label>
                <label className="block">
                  <span className="form-label">Confirm new password</span>
                  <input
                    className="form-input"
                    type="password"
                    autoComplete="new-password"
                    required
                    minLength={8}
                    value={confirmation}
                    onChange={(e) => setConfirmation(e.target.value)}
                  />
                </label>
              </>
            )}
            <button className="primary-button" disabled={busy}>
              {busy
                ? "Submitting..."
                : reset
                  ? "Reset password"
                  : "Send reset link"}
            </button>
          </>
        )}
        <p>
          <Link to="/login">Sign in</Link> |{" "}
          <Link to="/forgot-password">Request a new link</Link>
        </p>
      </form>
    </div></AuthLayout>
  );
}
