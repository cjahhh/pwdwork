import { StrictMode } from "react";
import { createRoot } from "react-dom/client";
import { BrowserRouter } from "react-router-dom";
import App from "./App.jsx";
import { AuthProvider } from "./context/AuthContext.jsx";
import { UiProvider } from "./context/UiContext.jsx";
import "./index.css";

// Keep local development on one origin so authentication and live data are shared
// between tabs. `localhost` and `127.0.0.1` have separate localStorage/session tokens.
if (import.meta.env.DEV && window.location.hostname === "127.0.0.1") {
  window.location.replace(`http://localhost:${window.location.port}${window.location.pathname}${window.location.search}${window.location.hash}`);
}

createRoot(document.getElementById("root")).render(
  <StrictMode>
    <BrowserRouter>
      <UiProvider>
        <AuthProvider>
          <App />
        </AuthProvider>
      </UiProvider>
    </BrowserRouter>
  </StrictMode>,
);
