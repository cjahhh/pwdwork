import {
  createContext,
  useContext,
  useEffect,
  useMemo,
  useState,
  useCallback,
  useRef,
} from "react";
import {
  fetchCurrentUser,
  loginAccount,
  logoutAccount,
  registerAccount,
} from "../services/auth.js";
import { apiErrorMessage } from "../services/api.js";
const AuthContext = createContext(null);
export function AuthProvider({ children }) {
  const [user, setUser] = useState(null),
    [ready, setReady] = useState(false),
    [error, setError] = useState(""),
    [notice, setNotice] = useState("");
  const channel = useRef(null);
  const restore = useCallback(async () => {
    setReady(false);
    setError("");
    try {
      setUser(await fetchCurrentUser());
    } catch (e) {
      if (e.response?.data?.code === "account_inactive") {
        setUser(null);
        setNotice(e.response.data.message);
      } else if ([401, 419].includes(e.response?.status)) setUser(null);
      else setError(apiErrorMessage(e));
    } finally {
      setReady(true);
    }
  }, []);
  useEffect(() => {
    const expired = (event) => {
      setUser(null);
      setError("");
      setNotice(event.detail?.message || "Your session has expired. Please sign in again.");
    };
    const verificationRequired = () => restore();
    window.addEventListener("pwdwork:unauthorized", expired);
    window.addEventListener("pwdwork:verification-required", verificationRequired);
    if (window.BroadcastChannel) {
      channel.current = new BroadcastChannel("pwdwork-session");
      channel.current.onmessage = () => restore();
    }
    Promise.resolve().then(restore);
    return () => {
      window.removeEventListener("pwdwork:unauthorized", expired);
      window.removeEventListener("pwdwork:verification-required", verificationRequired);
      channel.current?.close();
    };
  }, [restore]);
  const value = useMemo(
    () => ({
      user,
      ready,
      error,
      notice,
      retry: restore,
      async refreshUser() {
        const current = await fetchCurrentUser();
        setUser(current);
        channel.current?.postMessage("changed");
        return current;
      },
      async login(payload) {
        const data = await loginAccount(payload);
        setError("");
        setUser(data.user);
        setNotice("");
        channel.current?.postMessage("changed");
        return data.user;
      },
      async register(payload) {
        const data = await registerAccount(payload);
        setError("");
        setNotice("");
        setUser(data.user);
        channel.current?.postMessage("changed");
        return data.user;
      },
      async logout() {
        try {
          await logoutAccount();
          setUser(null);
          setNotice("");
          channel.current?.postMessage("changed");
        } catch (e) {
          if ([401, 419].includes(e.response?.status)) {
            setUser(null);
            return;
          }
          setError(apiErrorMessage(e));
          throw e;
        }
      },
    }),
    [user, ready, error, notice, restore],
  );
  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}
// oxlint-disable-next-line react/only-export-components
export function useAuth() {
  const context = useContext(AuthContext);
  if (!context) throw new Error("AuthProvider is required");
  return context;
}
