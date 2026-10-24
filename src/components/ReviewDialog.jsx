import { useEffect, useId, useRef } from "react";

export default function ReviewDialog({ title, onClose, busy = false, children }) {
  const ref = useRef(null);
  const titleId = useId();
  useEffect(() => {
    const previous = document.activeElement;
    const dialog = ref.current;
    dialog.showModal();
    return () => { dialog.close(); previous?.focus(); };
  }, []);
  return (
    <dialog ref={ref} aria-labelledby={titleId} aria-busy={busy}
      className="review-dialog panel" onCancel={(event) => { event.preventDefault(); if (!busy) onClose(); }}>
      <div className="flex flex-wrap items-center justify-between gap-3 mb-5">
        <h2 id={titleId}>{title}</h2>
        <button type="button" onClick={onClose} disabled={busy}>Close review</button>
      </div>
      {children}
    </dialog>
  );
}
