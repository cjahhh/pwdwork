import { StrictMode } from "react";
import { createRoot } from "react-dom/client";
import { createBrowserRouter, RouterProvider } from "react-router-dom";
import App from "./App.jsx";
import { AuthProvider } from "./context/AuthContext.jsx";
import { UiProvider } from "./context/UiContext.jsx";
import "./index.css";

// Keep local development on one origin so authentication and live data are shared
// between tabs. `localhost` and `127.0.0.1` have separate session cookies.

const router = createBrowserRouter([{ path: '*', element:
      <UiProvider>
        <AuthProvider>
          <App />
        </AuthProvider>
      </UiProvider>
}]);

createRoot(document.getElementById("root")).render(
  <StrictMode>
    <RouterProvider router={router} />
  </StrictMode>,
);
