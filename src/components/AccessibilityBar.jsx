import { useState } from "react";
import { useUi } from "../context/UiContext.jsx";

function fontButtonClass(active) {
  return active
    ? "px-2 py-0.5 rounded bg-brand-blue text-white font-bold"
    : "px-2 py-0.5 rounded text-gray-300 hover:text-white";
}

function AccessibilityBar() {
  const {
    fontSize,
    highContrast,
    darkMode,
    reducedMotion,
    dyslexiaFont,
    largeCursor,
    changeFontSize,
    toggleHighContrast,
    toggleScreenReaderMode,
    toggleDarkMode,
    toggleReducedMotion,
    toggleDyslexiaFont,
    toggleLargeCursor,
  } = useUi();
  const [expanded, setExpanded] = useState(false);

  return (
    <aside
      className="bg-brand-dark text-white text-sm py-2 px-4 border-b border-gray-800"
      aria-label="Accessibility settings"
    >
      <div className="max-w-[1440px] mx-auto flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-3">
          <span className="inline-flex items-center gap-1.5 bg-brand-teal px-2.5 py-0.5 rounded-full text-xs font-bold text-white tracking-wide">
            <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
              <path
                strokeLinecap="round"
                strokeLinejoin="round"
                strokeWidth="2.5"
                d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"
              />
            </svg>
            WCAG 2.1 AAA Compliant
          </span>
          <span className="text-xs text-gray-300 hidden md:inline">
            Accessible employment portal with verified accommodations.
          </span>
        </div>

        <div className="flex items-center flex-wrap gap-4 text-xs font-medium">
          <button
            type="button"
            onClick={() => setExpanded((current) => !current)}
            aria-expanded={expanded}
            className="bg-gray-800 hover:bg-gray-700 text-gray-200 px-3 py-1 rounded-lg border border-gray-700 transition font-bold"
          >
            Accessibility options {expanded ? "−" : "+"}
          </button>

          <button type="button" onClick={toggleReducedMotion} className="bg-gray-800 hover:bg-gray-700 text-gray-200 px-3 py-1 rounded-lg border border-gray-700 transition">Reduced motion: <strong>{reducedMotion ? "On" : "Off"}</strong></button>
          <button type="button" onClick={toggleDyslexiaFont} className="bg-gray-800 hover:bg-gray-700 text-gray-200 px-3 py-1 rounded-lg border border-gray-700 transition">Reading font: <strong>{dyslexiaFont ? "Friendly" : "Standard"}</strong></button>
          <button type="button" onClick={toggleLargeCursor} className="bg-gray-800 hover:bg-gray-700 text-gray-200 px-3 py-1 rounded-lg border border-gray-700 transition">Cursor: <strong>{largeCursor ? "Large" : "Standard"}</strong></button>

          <div className={`${expanded ? "flex" : "hidden"} items-center flex-wrap gap-4`}>
          <div className="flex items-center bg-gray-800 rounded-lg p-1 border border-gray-700">
            <span className="px-2 text-gray-300 font-bold">Text Size:</span>
            <button
              type="button"
              onClick={() => changeFontSize("normal")}
              className={fontButtonClass(fontSize === "normal")}
              title="Default font size"
            >
              100%
            </button>
            <button
              type="button"
              onClick={() => changeFontSize("large")}
              className={fontButtonClass(fontSize === "large")}
              title="115% larger font size"
            >
              115%
            </button>
            <button
              type="button"
              onClick={() => changeFontSize("xlarge")}
              className={fontButtonClass(fontSize === "xlarge")}
              title="130% extra large font size"
            >
              130%
            </button>
          </div>

          <button
            type="button"
            onClick={toggleHighContrast}
            className="flex items-center gap-1.5 bg-gray-800 hover:bg-gray-700 text-gray-200 px-3 py-1 rounded-lg border border-gray-700 transition"
          >
            <svg className="w-4 h-4 text-brand-yellow" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
              <path d="M10 2a8 8 0 100 16 8 8 0 000-16zM1 10a9 9 0 1118 0 9 9 0 01-18 0z" />
            </svg>
            <span>
              High Contrast: <strong>{highContrast ? "On" : "Off"}</strong>
            </span>
          </button>

          <button
            type="button"
            onClick={toggleScreenReaderMode}
            className="flex items-center gap-1.5 bg-gray-800 hover:bg-gray-700 text-gray-200 px-3 py-1 rounded-lg border border-gray-700 transition"
          >
            <svg className="w-4 h-4 text-brand-teal" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
              <path
                strokeLinecap="round"
                strokeLinejoin="round"
                strokeWidth="2"
                d="M15.536 8.464a5 5 0 010 7.072m2.828-9.9a9 9 0 010 12.728M5.586 15H4a1 1 0 01-1-1v-4a1 1 0 011-1h1.586l4.707-4.707C10.923 3.663 12 4.109 12 5v14c0 .891-1.077 1.337-1.707.707L5.586 15z"
              />
            </svg>
            <span>Screen Reader Mode</span>
          </button>

          <button
            type="button"
            onClick={toggleDarkMode}
            className="flex items-center gap-1.5 bg-gray-800 hover:bg-gray-700 text-gray-200 px-3 py-1 rounded-lg border border-gray-700 transition"
          >
            <span aria-hidden="true">{darkMode ? "☀" : "☾"}</span>
            <span>Dark Mode: <strong>{darkMode ? "On" : "Off"}</strong></span>
          </button>
          </div>
        </div>
      </div>
    </aside>
  );
}

export default AccessibilityBar;
