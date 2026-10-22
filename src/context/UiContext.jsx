import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useRef,
  useState,
} from "react";
import { useLocation } from "react-router-dom";
const UiContext = createContext(null);
function read(key, fallback) {
  try {
    const raw = localStorage.getItem("pwdwork_" + key);
    return raw === null ? fallback : JSON.parse(raw);
  } catch {
    return fallback;
  }
}
function useSetting(key, fallback) {
  const [value, setValue] = useState(() => read(key, fallback));
  useEffect(() => {
    try {
      localStorage.setItem("pwdwork_" + key, JSON.stringify(value));
    } catch {
      /* Settings remain usable when browser storage is unavailable. */
    }
  }, [key, value]);
  return [value, setValue];
}
export function UiProvider({ children }) {
  const [fontSize, setFontSize] = useSetting("font_size", "normal"),
    [highContrast, setHighContrast] = useSetting("high_contrast", false),
    [darkMode, setDarkMode] = useSetting(
      "dark_mode",
      window.matchMedia("(prefers-color-scheme: dark)").matches,
    ),
    [reducedMotion, setReducedMotion] = useSetting(
      "reduced_motion",
      window.matchMedia("(prefers-reduced-motion: reduce)").matches,
    ),
    [dyslexiaFont, setDyslexiaFont] = useSetting("dyslexia_font", false),
    [largeCursor, setLargeCursor] = useSetting("large_cursor", false);
  const [toast, setToast] = useState({
      visible: false,
      title: "",
      subtext: "",
    }),
    [modal, setModal] = useState(null),
    [announcement, setAnnouncement] = useState(""),
    [reading, setReading] = useState(false);
  const timer = useRef(null),
    { pathname } = useLocation();
  useEffect(() => {
    const classes = {
      "font-size-large": fontSize === "large",
      "font-size-xlarge": fontSize === "xlarge",
      "high-contrast": highContrast,
      "dark-mode": darkMode,
      "reduced-motion": reducedMotion,
      "dyslexia-font": dyslexiaFont,
      "large-cursor": largeCursor,
    };
    Object.entries(classes).forEach(([name, enabled]) =>
      document.documentElement.classList.toggle(name, enabled),
    );
  }, [
    fontSize,
    highContrast,
    darkMode,
    reducedMotion,
    dyslexiaFont,
    largeCursor,
  ]);
  useEffect(() => {
    window.speechSynthesis?.cancel();
    Promise.resolve().then(() => setReading(false));
  }, [pathname]);
  useEffect(
    () => () => {
      clearTimeout(timer.current);
      window.speechSynthesis?.cancel();
    },
    [],
  );
  const showToast = useCallback((title, subtext) => {
    setToast({ visible: true, title, subtext });
    clearTimeout(timer.current);
    timer.current = setTimeout(
      () => setToast((t) => ({ ...t, visible: false })),
      8000,
    );
  }, []);
  const toggleReadAloud = useCallback(() => {
    if (!window.speechSynthesis) {
      showToast(
        "Read aloud unavailable",
        "This browser does not support text to speech.",
      );
      return;
    }
    if (window.speechSynthesis.speaking) {
      window.speechSynthesis.cancel();
      setReading(false);
      return;
    }
    const utterance = new SpeechSynthesisUtterance(
      document.querySelector("main")?.innerText || "No page content available.",
    );
    utterance.onend = () => setReading(false);
    utterance.onerror = () => setReading(false);
    window.speechSynthesis.speak(utterance);
    setReading(true);
  }, [showToast]);
  const value = {
    fontSize,
    highContrast,
    darkMode,
    reducedMotion,
    dyslexiaFont,
    largeCursor,
    toast,
    modal,
    announcement,
    reading,
    showToast,
    setAnnouncement,
    openModal: setModal,
    closeModal: () => setModal(null),
    changeFontSize: setFontSize,
    toggleHighContrast: () => setHighContrast((v) => !v),
    toggleDarkMode: () => setDarkMode((v) => !v),
    toggleReducedMotion: () => setReducedMotion((v) => !v),
    toggleDyslexiaFont: () => setDyslexiaFont((v) => !v),
    toggleLargeCursor: () => setLargeCursor((v) => !v),
    toggleReadAloud,
  };
  return <UiContext.Provider value={value}>{children}</UiContext.Provider>;
}
// oxlint-disable-next-line react/only-export-components
export function useUi() {
  const context = useContext(UiContext);
  if (!context) throw new Error("UiProvider is required");
  return context;
}
