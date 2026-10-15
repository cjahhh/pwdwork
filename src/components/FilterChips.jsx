import { filterChipsData } from "../services/jobs.js";

function FilterChips({ activeChip, onToggle, onClear }) {
  return (
    <section className="bg-white py-8 border-b border-gray-200">
      <div className="max-w-[1440px] mx-auto px-6 lg:px-12">
        <div className="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6">
          <div>
            <h2 className="text-xl font-extrabold text-brand-dark flex items-center gap-2">
              <svg className="w-6 h-6 text-brand-teal" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path
                  strokeLinecap="round"
                  strokeLinejoin="round"
                  strokeWidth="2"
                  d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"
                />
              </svg>
              <span>Quick Filter by Accessibility Feature:</span>
            </h2>
            <p className="text-sm text-gray-600">
              Select accommodation tags to instantly refine job listings matching your workstation needs.
            </p>
          </div>

          <button
            type="button"
            onClick={onClear}
            className="text-sm font-bold text-brand-teal hover:underline self-start md:self-auto flex items-center gap-1"
          >
            <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
              <path
                strokeLinecap="round"
                strokeLinejoin="round"
                strokeWidth="2"
                d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"
              />
            </svg>
            <span>Reset All Filters</span>
          </button>
        </div>

        <div className="flex flex-wrap items-center gap-3">
          {filterChipsData.map((chip) => {
            const isSelected = activeChip === chip.id;
            return (
              <button
                key={chip.id}
                type="button"
                onClick={() => onToggle(chip.id)}
                className={`px-5 py-3 rounded-2xl font-bold text-base flex items-center gap-2.5 transition duration-150 ${
                  isSelected
                    ? "bg-brand-blue text-white shadow-soft-lg ring-2 ring-brand-blue"
                    : "bg-gray-100 hover:bg-gray-200 text-gray-800 border border-gray-300"
                }`}
                aria-pressed={isSelected}
              >
                <span className="text-xl" aria-hidden="true">
                  {chip.icon}
                </span>
                <span>{chip.label}</span>
                {isSelected ? (
                  <span className="ml-1 text-xs bg-brand-yellow text-brand-dark px-2 py-0.5 rounded-full font-extrabold">
                    Active
                  </span>
                ) : null}
              </button>
            );
          })}
        </div>
      </div>
    </section>
  );
}

export default FilterChips;
