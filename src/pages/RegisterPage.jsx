import { useState } from "react";
import { Link, useNavigate, useSearchParams } from "react-router-dom";
import { useAuth } from "../context/AuthContext.jsx";
import { useUi } from "../context/UiContext.jsx";
import { apiErrorMessage } from "../services/api.js";
import { accountPath } from "../services/auth.js";
import AuthLayout from "../components/AuthLayout.jsx";

function RegisterPage() {
  const { register } = useAuth();
  const { showToast } = useUi();
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const [role, setRole] = useState(searchParams.get("role") === "employer" ? "employer" : "applicant");
  const [name, setName] = useState("");
  const [companyName, setCompanyName] = useState("");
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
      const user = await register({
        name,
        email,
        password,
        role,
        company_name: role === "employer" ? companyName : undefined,
      });
      showToast("Account created", `Welcome to PWDWork, ${user.name}.`);
      navigate(accountPath(user), { replace: true });
    } catch (err) {
      setError(apiErrorMessage(err, "Could not create the account."));
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <AuthLayout>
        <div className="auth-card space-y-6">
          <div>
            <p className="text-xs text-brand-teal font-bold uppercase tracking-wider">Join PWDWork</p>
            <h1 className="text-3xl font-black text-brand-dark mt-1">Sign Up</h1>
            <p className="text-gray-600 mt-2">
              Register as a Jobseeker with Accessibility Accommodations or an Inclusive Employer.
            </p>
          </div>

          {error ? (
            <p className="bg-red-50 border border-red-200 text-red-800 font-semibold rounded-xl px-4 py-3" role="alert">
              {error}
            </p>
          ) : null}

          <form className="space-y-4" onSubmit={onSubmit}>
            <fieldset>
              <legend className="block text-xs font-extrabold uppercase tracking-wider text-brand-teal mb-2">
                I am registering as
              </legend>
              <div className="grid grid-cols-2 gap-3">
                <label className={`border-2 rounded-2xl p-3 font-bold cursor-pointer ${role === "applicant" ? "border-brand-blue bg-blue-50" : "border-gray-200"}`}>
                  <input
                    type="radio"
                    name="role"
                    value="applicant"
                    checked={role === "applicant"}
                    onChange={() => setRole("applicant")}
                    className="accent-brand-blue"
                  />
                  Jobseeker
                </label>
                <label className={`border-2 rounded-2xl p-3 font-bold cursor-pointer ${role === "employer" ? "border-brand-teal bg-brand-teal-light" : "border-gray-200"}`}>
                  <input
                    type="radio"
                    name="role"
                    value="employer"
                    checked={role === "employer"}
                    onChange={() => setRole("employer")}
                    className="accent-brand-teal"
                  />
                  Employer
                </label>
              </div>
            </fieldset>

            <div>
              <label htmlFor="register-name" className="block text-xs font-extrabold uppercase tracking-wider text-brand-teal mb-1">
                Full name
              </label>
              <input
                id="register-name"
                required
                value={name}
                onChange={(event) => setName(event.target.value)}
                className="w-full px-4 py-3 bg-gray-50 border-2 border-gray-200 rounded-xl font-medium text-brand-dark focus:bg-white focus:border-brand-blue"
              />
            </div>

            {role === "employer" ? (
              <div>
                <label htmlFor="register-company" className="block text-xs font-extrabold uppercase tracking-wider text-brand-teal mb-1">
                  Company name
                </label>
                <input
                  id="register-company"
                  required
                  value={companyName}
                  onChange={(event) => setCompanyName(event.target.value)}
                  className="w-full px-4 py-3 bg-gray-50 border-2 border-gray-200 rounded-xl font-medium text-brand-dark focus:bg-white focus:border-brand-blue"
                />
              </div>
            ) : null}

            <div>
              <label htmlFor="register-email" className="block text-xs font-extrabold uppercase tracking-wider text-brand-teal mb-1">
                Email
              </label>
              <input
                id="register-email"
                type="email"
                autoComplete="email"
                required
                value={email}
                onChange={(event) => setEmail(event.target.value)}
                className="w-full px-4 py-3 bg-gray-50 border-2 border-gray-200 rounded-xl font-medium text-brand-dark focus:bg-white focus:border-brand-blue"
              />
            </div>
            <div>
              <label htmlFor="register-password" className="block text-xs font-extrabold uppercase tracking-wider text-brand-teal mb-1">
                Password (at least 8 characters)
              </label>
              <div className="password-control"><input
                id="register-password"
                type={showPassword ? 'text' : 'password'}
                autoComplete="new-password"
                required
                minLength={8}
                value={password}
                onChange={(event) => setPassword(event.target.value)}
                className="w-full px-4 py-3 bg-gray-50 border-2 border-gray-200 rounded-xl font-medium text-brand-dark focus:bg-white focus:border-brand-blue"
              />
              <button type="button" aria-controls="register-password" aria-pressed={showPassword} onClick={() => setShowPassword(value => !value)}>{showPassword ? 'Hide password' : 'Show password'}</button></div>
            </div>
            <button
              type="submit"
              disabled={submitting}
              className="w-full bg-brand-blue hover:bg-brand-blue-dark text-white font-black py-3.5 rounded-2xl disabled:opacity-60"
            >
              {submitting ? "Creating account..." : "Create account"}
            </button>
          </form>

          <p className="text-sm text-gray-600">
            Already have an account?{" "}
            <Link to="/login" className="text-brand-blue font-extrabold hover:underline">
              Log in
            </Link>
          </p>
        </div>
    </AuthLayout>
  );
}

export default RegisterPage;
