import { createContext, useCallback, useContext, useEffect, useMemo, useState } from "react";

const UiContext = createContext(null);

export function UiProvider({ children }) {
  const [fontSize, setFontSize] = useState("normal");
  const [highContrast, setHighContrast] = useState(false);
  const [darkMode, setDarkMode] = useState(() => localStorage.getItem("pwdwork_dark_mode") === "true");
  const [reducedMotion, setReducedMotion] = useState(() => localStorage.getItem("pwdwork_reduced_motion") === "true");
  const [dyslexiaFont, setDyslexiaFont] = useState(() => localStorage.getItem("pwdwork_dyslexia_font") === "true");
  const [largeCursor, setLargeCursor] = useState(() => localStorage.getItem("pwdwork_large_cursor") === "true");
  const [toast, setToast] = useState({
    visible: false,
    title: "",
    subtext: "",
  });
  const [modal, setModal] = useState(null);
  const [announcement, setAnnouncement] = useState(
    "Welcome to PWDWork. Accessibility tools enabled.",
  );

  useEffect(() => {
    const root = document.documentElement;
    root.classList.toggle("font-size-large", fontSize === "large");
    root.classList.toggle("font-size-xlarge", fontSize === "xlarge");
  }, [fontSize]);

  useEffect(() => {
    document.documentElement.classList.toggle("high-contrast", highContrast);
  }, [highContrast]);

  useEffect(() => {
    document.documentElement.classList.toggle("dark-mode", darkMode);
    localStorage.setItem("pwdwork_dark_mode", String(darkMode));
  }, [darkMode]);

  useEffect(() => { document.documentElement.classList.toggle("reduced-motion", reducedMotion); localStorage.setItem("pwdwork_reduced_motion", String(reducedMotion)); }, [reducedMotion]);
  useEffect(() => { document.documentElement.classList.toggle("dyslexia-font", dyslexiaFont); localStorage.setItem("pwdwork_dyslexia_font", String(dyslexiaFont)); }, [dyslexiaFont]);
  useEffect(() => { document.documentElement.classList.toggle("large-cursor", largeCursor); localStorage.setItem("pwdwork_large_cursor", String(largeCursor)); }, [largeCursor]);

  const showToast = useCallback((title, subtext) => {
    setToast({ visible: true, title, subtext });
    window.clearTimeout(showToast.timeoutId);
    showToast.timeoutId = window.setTimeout(() => {
      setToast((current) => ({ ...current, visible: false }));
    }, 3000);
  }, []);

  const openModal = useCallback((nextModal) => {
    setModal(nextModal);
  }, []);

  const closeModal = useCallback(() => {
    setModal(null);
  }, []);

  const changeFontSize = useCallback(
    (size) => {
      setFontSize(size);
      if (size === "large") {
        showToast("Font Sizing Changed", "Text size adjusted to 115%.");
      } else if (size === "xlarge") {
        showToast("Font Sizing Changed", "Text size adjusted to 130%.");
      } else {
        showToast("Font Sizing Reset", "Text size set to default 100%.");
      }
    },
    [showToast],
  );

  const toggleHighContrast = useCallback(() => {
    setHighContrast((current) => {
      const next = !current;
      showToast(
        "High Contrast Mode",
        next ? "High contrast black theme activated." : "Standard warm theme restored.",
      );
      return next;
    });
  }, [showToast]);

  const toggleScreenReaderMode = useCallback(() => {
    showToast("Screen Reader Mode Active", "Announcements and focus indicators enhanced.");
    setAnnouncement("Screen reader mode activated. Navigating PWDWork.");
  }, [showToast]);

  const toggleDarkMode = useCallback(() => {
    setDarkMode((current) => {
      const next = !current;
      showToast("Dark Mode", next ? "Dark mode activated." : "Light mode restored.");
      return next;
    });
  }, [showToast]);

  const toggleAccessibilityOption = useCallback((setter, label, enabledText, disabledText) => {
    setter((current) => { const next = !current; showToast(label, next ? enabledText : disabledText); return next; });
  }, [showToast]);

  const value = useMemo(
    () => ({
      fontSize,
      highContrast,
      darkMode,
      toast,
      modal,
      announcement,
      showToast,
      openModal,
      closeModal,
      changeFontSize,
      toggleHighContrast,
      toggleScreenReaderMode,
      toggleDarkMode,
      reducedMotion,
      dyslexiaFont,
      largeCursor,
      toggleReducedMotion: () => toggleAccessibilityOption(setReducedMotion, "Reduced motion", "Animations reduced.", "Animations restored."),
      toggleDyslexiaFont: () => toggleAccessibilityOption(setDyslexiaFont, "Reading font", "Dyslexia-friendly font enabled.", "Standard font restored."),
      toggleLargeCursor: () => toggleAccessibilityOption(setLargeCursor, "Large cursor", "Large cursor enabled.", "Standard cursor restored."),
      setAnnouncement,
    }),
    [
      fontSize,
      highContrast,
      darkMode,
      toast,
      modal,
      announcement,
      showToast,
      openModal,
      closeModal,
      changeFontSize,
      toggleHighContrast,
      toggleScreenReaderMode,
      toggleDarkMode,
      reducedMotion,
      dyslexiaFont,
      largeCursor,
      toggleAccessibilityOption,
    ],
  );

  return <UiContext.Provider value={value}>{children}</UiContext.Provider>;
}

export function useUi() {
  const context = useContext(UiContext);
  if (!context) {
    throw new Error("useUi must be used within UiProvider");
  }
  return context;
}
