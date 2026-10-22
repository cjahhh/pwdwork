import { useUi } from "../context/UiContext.jsx";
export default function AccessibilityBar() {
  const ui = useUi();
  const toggles = [
    ["High contrast", "highContrast", "toggleHighContrast"],
    ["Dark mode", "darkMode", "toggleDarkMode"],
    ["Reduced motion", "reducedMotion", "toggleReducedMotion"],
    ["Dyslexia-friendly reading font", "dyslexiaFont", "toggleDyslexiaFont"],
    ["Large cursor", "largeCursor", "toggleLargeCursor"],
  ];
  return (
    <aside
      className="bg-brand-dark text-white p-3"
      aria-label="Accessibility settings"
    >
      <details>
        <summary>Accessibility options</summary>
        <div className="flex flex-wrap gap-3 py-3">
          <label>
            Text size{" "}
            <select
              className="bg-brand-dark border rounded p-2"
              value={ui.fontSize}
              onChange={(e) => ui.changeFontSize(e.target.value)}
            >
              <option value="normal">100%</option>
              <option value="large">115%</option>
              <option value="xlarge">130%</option>
            </select>
          </label>
          {toggles.map(([label, state, action]) => (
            <button
              className="border rounded p-2"
              key={state}
              type="button"
              aria-pressed={ui[state]}
              onClick={ui[action]}
            >
              {label}: {ui[state] ? "On" : "Off"}
            </button>
          ))}
          <button
            className="border rounded p-2"
            type="button"
            aria-pressed={ui.reading}
            onClick={ui.toggleReadAloud}
          >
            {ui.reading ? "Stop reading" : "Read page aloud"}
          </button>
        </div>
        <p className="text-sm">
          Read aloud uses browser text to speech. Your screen reader works
          independently of these settings.
        </p>
      </details>
    </aside>
  );
}
