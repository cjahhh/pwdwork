import { createContext, useContext, useEffect, useMemo, useState } from "react";
import { fetchCurrentUser, loginAccount, logoutAccount, registerAccount } from "../services/auth.js";
import { getStoredToken, setStoredToken } from "../services/api.js";

const AuthContext = createContext(null);

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null);
  const [ready, setReady] = useState(false);

  useEffect(() => {
    async function restoreSession() {
      if (!getStoredToken()) {
        setReady(true);
        return;
      }

      try {
        const current = await fetchCurrentUser();
        setUser(current);
      } catch {
        setStoredToken(null);
        setUser(null);
      } finally {
        setReady(true);
      }
    }

    restoreSession();
  }, []);

  const value = useMemo(
    () => ({
      user,
      ready,
      async register(payload) {
        const data = await registerAccount(payload);
        setStoredToken(data.token);
        setUser(data.user);
        return data.user;
      },
      async login(payload) {
        const data = await loginAccount(payload);
        setStoredToken(data.token);
        setUser(data.user);
        return data.user;
      },
      async logout() {
        try {
          await logoutAccount();
        } catch {
          // Token may already be invalid; still clear local session.
        }
        setStoredToken(null);
        setUser(null);
      },
    }),
    [user, ready],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth() {
  const context = useContext(AuthContext);
  if (!context) {
    throw new Error("useAuth must be used inside AuthProvider");
  }
  return context;
}
