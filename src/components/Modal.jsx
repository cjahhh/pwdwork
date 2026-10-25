import { useEffect, useRef } from "react";
import { useUi } from "../context/UiContext.jsx";
export default function Modal() {
  const { modal, closeModal } = useUi(),
    ref = useRef(null);
  useEffect(() => {
    const dialog = ref.current;
    if (!modal || !dialog) return;
    const previous = document.activeElement;
    dialog.showModal();
    return () => {
      dialog.close();
      previous?.focus();
    };
  }, [modal]);
  return (
    <dialog
      ref={ref}
      aria-labelledby="modal-heading"
      onCancel={closeModal}
      className="review-dialog rounded-2xl p-6 max-w-2xl w-[90%] bg-white text-brand-dark"
    >
      <h2 id="modal-heading">{modal?.title}</h2>
      <div>{modal?.content}</div>
      {modal?.footer}
      <button type="button" className="primary-button" onClick={closeModal}>
        Close dialog
      </button>
    </dialog>
  );
}
