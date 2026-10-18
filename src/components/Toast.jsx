import { useUi } from "../context/UiContext.jsx";

function Toast() {
  const { toast } = useUi();

  return (
    <div
      className={`fixed bottom-6 right-6 z-50 transition-all duration-300 pointer-events-none ${
        toast.visible ? "translate-y-0 opacity-100" : "translate-y-20 opacity-0"
      }`}
      role="status"
      aria-live="polite"
    >
      <div className="bg-brand-dark text-white px-5 py-3.5 rounded-2xl shadow-soft-xl border-2 border-brand-teal flex items-center gap-3">
        <div className="w-8 h-8 rounded-full bg-brand-teal text-white flex items-center justify-center font-bold text-sm">
          ✓
        </div>
        <div>
          <p className="font-bold text-sm">{toast.title || "Action completed successfully."}</p>
          <p className="text-xs text-gray-300">{toast.subtext || "Accessibility filter applied."}</p>
        </div>
      </div>
    </div>
  );
}

export default Toast;
