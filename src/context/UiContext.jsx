import { createContext, useCallback, useContext, useEffect, useMemo, useState } from "react";

const UiContext = createContext(null);

export function UiProvider({ children }) {
  const [fontSize, setFontSize] = useState("normal");
  const [highContrast, setHighContrast] = useState(false);
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

  const value = useMemo(
    () => ({
      fontSize,
      highContrast,
      toast,
      modal,
      announcement,
      showToast,
      openModal,
      closeModal,
      changeFontSize,
      toggleHighContrast,
      toggleScreenReaderMode,
      setAnnouncement,
    }),
    [
      fontSize,
      highContrast,
      toast,
      modal,
      announcement,
      showToast,
      openModal,
      closeModal,
      changeFontSize,
      toggleHighContrast,
      toggleScreenReaderMode,
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
