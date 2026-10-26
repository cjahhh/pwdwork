import { useEffect, useState } from "react";
import { Link, useNavigate } from "react-router-dom";
import AuthLayout from "../components/AuthLayout.jsx";
import { useAuth } from "../context/AuthContext.jsx";
import { apiErrorMessage } from "../services/api.js";
import { accountPath, confirmVerification, resendVerification } from "../services/auth.js";

export default function VerifyEmailPage() {
  const { user, ready, error: authError, retry, login, logout, refreshUser } = useAuth();
  const navigate = useNavigate();
  // Keep the proof in component memory only; never browser storage or a redirect URL.
  const [proof, setProof] = useState(() => new URLSearchParams(window.location.hash.slice(1)).get("proof") || "");
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [message, setMessage] = useState("");
  const [waitUntil, setWaitUntil] = useState(0);
  const [now, setNow] = useState(() => Date.now());
  const remaining = Math.max(0, Math.ceil((waitUntil - now) / 1000));

  useEffect(() => {
    const captureLink = () => {
      const incoming = new URLSearchParams(window.location.hash.slice(1)).get("proof");
      if (incoming) setProof(incoming);
      if (window.location.hash) {
        window.history.replaceState(window.history.state, "", window.location.pathname + window.location.search);
      }
    };
    if (window.location.hash) {
      window.history.replaceState(window.history.state, "", window.location.pathname + window.location.search);
    }
    window.addEventListener("hashchange", captureLink);
    const timer = window.setInterval(() => setNow(Date.now()), 1000);
    return () => {
      window.clearInterval(timer);
      window.removeEventListener("hashchange", captureLink);
    };
  }, []);

  async function act(action) {
    setBusy(true);
    setError("");
    setMessage("");
    try {
      await action();
    } catch (failure) {
      // Never log the error object: Axios request configuration can contain the proof.
      setError(apiErrorMessage(failure));
      const retryAfter = Number(failure.response?.headers?.["retry-after"]);
      if (failure.response?.status === 429 && Number.isFinite(retryAfter) && retryAfter > 0) {
        setWaitUntil(Date.now() + retryAfter * 1000);
        setNow(Date.now());
      }
    } finally {
      setBusy(false);
    }
  }

  async function signIn(event) {
    event.preventDefault();
    await act(async () => {
      await login({ email, password });
      setPassword("");
      setMessage("Signed in. Choose Confirm email to finish verification.");
    });
    setPassword("");
  }

  return <AuthLayout><div className="auth-card space-y-5" aria-busy={busy}>
    <h1 className="text-3xl font-black text-brand-dark">Verify your email</h1>
    <p>Open the latest PWDWork verification email and confirm your address. Links expire after 60 minutes.</p>
    {error && <p role="alert">{error}</p>}
    {message && <p role="status">{message}</p>}
    {!ready ? <p role="status">Loading account...</p> : authError ? <>
      <p role="alert">{authError}</p><button className="secondary-button" onClick={retry}>Retry loading account</button>
    </> : !user ? <form className="space-y-4" onSubmit={signIn}>
      <p>Sign in to the account named in the email. Your link stays on this page while you sign in.</p>
      <label className="block">Email<input className="form-input" type="email" autoComplete="email" required value={email} onChange={(event) => setEmail(event.target.value)} /></label>
      <label className="block">Password<input className="form-input" type="password" autoComplete="current-password" required value={password} onChange={(event) => setPassword(event.target.value)} /></label>
      <button className="primary-button" disabled={busy}>Sign in</button>
      <p><Link to="/forgot-password">Forgot password?</Link></p>
    </form> : <>
      <p>Signed in as <strong>{user.email}</strong>.</p>
      {user.email_verified ? <>
        <p>Your email address is verified.</p>
        <Link className="primary-button" to={accountPath(user)}>Continue to your account</Link>
      </> : <>
        <p>{user.email_verification_required
          ? "Please verify your email before using your account features. You can still browse public jobs."
          : "Your existing account access is unchanged. Confirming your email establishes ownership of this address."}</p>
        {proof ? <button className="primary-button" disabled={busy} onClick={() => act(async () => {
          const data = await confirmVerification(proof);
          setProof("");
          await refreshUser();
          setMessage(data.message);
        })}>Confirm email</button> : <p>Use the button in your email to open a verification link. If this page was refreshed after opening a link, reopen it from the email.</p>}
        {user.email_verification_available && <button className="secondary-button" disabled={busy || remaining > 0} onClick={() => act(async () => {
          const data = await resendVerification();
          setMessage(data.message);
          setWaitUntil(Date.now() + 60000);
          setNow(Date.now());
        })}>{remaining > 0 ? `Resend available in ${remaining}s` : "Resend verification email"}</button>}
        {!user.email_verification_available && <p>Email verification is not enabled for this account yet.</p>}
      </>}
      <button className="secondary-button" disabled={busy} onClick={() => act(async () => {
        await logout();
        setMessage("Signed out. You can sign in to the matching account here.");
      })}>Log out</button>
      <button className="secondary-button" disabled={busy} onClick={() => act(async () => {
        const current = await refreshUser();
        if (!current.email_verification_required) navigate(accountPath(current), { replace: true });
        else setMessage("Your email address still needs verification.");
      })}>Check verification status</button>
    </>}
    <p><Link to="/jobs">Browse public jobs</Link></p>
  </div></AuthLayout>;
}
