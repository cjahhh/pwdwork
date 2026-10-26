import { useState } from "react";
import { Link, useNavigate } from "react-router-dom";
import { useAuth } from "../context/AuthContext.jsx";
import { useUi } from "../context/UiContext.jsx";
import { apiErrorMessage } from "../services/api.js";
import { accountPath } from "../services/auth.js";
import AuthLayout from "../components/AuthLayout.jsx";

function LoginPage() {
  const { login } = useAuth();
  const { showToast } = useUi();
  const navigate = useNavigate();
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [showPassword, setShowPassword] = useState(false);
  const [error, setError] = useState("");
  const [submitting, setSubmitting] = useState(false);

  async function onSubmit(event) {
    event.preventDefault();
    setError("");
    setSubmitting(true);

    try {
      const user = await login({ email, password });
      showToast("Logged in", `Welcome back, ${user.name}.`);
      navigate(accountPath(user), { replace: true });
    } catch (err) {
      setError(apiErrorMessage(err, "Could not log in."));
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <AuthLayout>
        <div className="auth-card space-y-6">
          <div>
            <p className="text-xs text-brand-teal font-bold uppercase tracking-wider">PWDWork Account</p>
            <h1 className="text-3xl font-black text-brand-dark mt-1">Log In</h1>
            <p className="text-gray-600 mt-2">
              Login to your PWDWork Jobseeker or Employer Account.
            </p>
          </div>

          {error ? (
            <p className="bg-red-50 border border-red-200 text-red-800 font-semibold rounded-xl px-4 py-3" role="alert">
              {error}
            </p>
          ) : null}

          <form className="space-y-4" onSubmit={onSubmit}>
            <div>
              <label htmlFor="login-email" className="block text-xs font-extrabold uppercase tracking-wider text-brand-teal mb-1">
                Email
              </label>
              <input
                id="login-email"
                type="email"
                autoComplete="email"
                required
                value={email}
                onChange={(event) => setEmail(event.target.value)}
                className="w-full px-4 py-3 bg-gray-50 border-2 border-gray-200 rounded-xl font-medium text-brand-dark focus:bg-white focus:border-brand-blue"
              />
            </div>
            <div>
              <label htmlFor="login-password" className="block text-xs font-extrabold uppercase tracking-wider text-brand-teal mb-1">
                Password
              </label>
              <div className="password-control"><input
                id="login-password"
                type={showPassword ? 'text' : 'password'}
                autoComplete="current-password"
                required
                value={password}
                onChange={(event) => setPassword(event.target.value)}
                className="w-full px-4 py-3 bg-gray-50 border-2 border-gray-200 rounded-xl font-medium text-brand-dark focus:bg-white focus:border-brand-blue"
              />
              <button type="button" aria-controls="login-password" aria-pressed={showPassword} onClick={() => setShowPassword(value => !value)}>{showPassword ? 'Hide password' : 'Show password'}</button></div>
            </div>
            <button
              type="submit"
              disabled={submitting}
              className="w-full bg-brand-blue hover:bg-brand-blue-dark text-white font-black py-3.5 rounded-2xl disabled:opacity-60"
            >
              {submitting ? "Signing in..." : "Continue"}
            </button>
          </form>

          <div className="text-sm text-gray-600 flex flex-col gap-2">
            <Link to="/forgot-password" className="text-brand-teal font-bold hover:underline">
              Forgot password?
            </Link>
            <p>
              Need an account?{" "}
              <Link to="/register" className="text-brand-blue font-extrabold hover:underline">
                Sign up
              </Link>
            </p>
          </div>
        </div>
    </AuthLayout>
  );
}

export default LoginPage;
