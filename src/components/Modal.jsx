import { useEffect } from "react";
import { useUi } from "../context/UiContext.jsx";

function Modal() {
  const { modal, closeModal } = useUi();

  useEffect(() => {
    if (!modal) return undefined;

    function onKeyDown(event) {
      if (event.key === "Escape") closeModal();
    }

    document.addEventListener("keydown", onKeyDown);
    return () => document.removeEventListener("keydown", onKeyDown);
  }, [modal, closeModal]);

  if (!modal) return null;

  return (
    <div
      className="fixed inset-0 z-50 bg-black/70 backdrop-blur-sm flex items-center justify-center p-4"
      role="dialog"
      aria-modal="true"
      aria-labelledby="modalHeading"
      onClick={closeModal}
    >
      <div
        className="bg-white rounded-3xl max-w-2xl w-full p-6 sm:p-8 shadow-soft-xl border-2 border-brand-blue relative"
        onClick={(event) => event.stopPropagation()}
      >
        <div className="flex items-center justify-between border-b border-gray-200 pb-4 mb-4">
          <div className="flex items-center gap-3">
            <div className="w-10 h-10 rounded-xl bg-brand-blue text-white flex items-center justify-center font-bold">
              ✓
            </div>
            <div>
              <h3 id="modalHeading" className="text-2xl font-black text-brand-dark">
                {modal.title}
              </h3>
              <p className="text-xs text-brand-teal font-bold uppercase tracking-wider">
                PWDWork Accessible Action View
              </p>
            </div>
          </div>
          <button
            type="button"
            onClick={closeModal}
            className="w-10 h-10 rounded-xl bg-gray-100 hover:bg-gray-200 text-gray-700 font-bold flex items-center justify-center transition"
            aria-label="Close dialog"
          >
            ✕
          </button>
        </div>

        <div className="space-y-4 text-base text-gray-700 leading-relaxed">{modal.content}</div>

        <div className="mt-6 pt-4 border-t border-gray-200 flex items-center justify-end gap-3">
          {modal.footer || (
            <button
              type="button"
              onClick={closeModal}
              className="bg-brand-blue text-white font-extrabold px-6 py-2.5 rounded-xl"
            >
              Close Preview
            </button>
          )}
        </div>
      </div>
    </div>
  );
}

export default Modal;
