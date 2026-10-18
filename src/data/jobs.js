export const jobsDataset = [
  {
    id: 1,
    title: "Senior UX/UI Accessibility Designer",
    company: "InclusiveTech Labs",
    companyLogo: "IT",
    companyColor: "bg-blue-600",
    location: "Remote (Global)",
    type: "Full Time",
    description:
      "Lead the design of inclusive web applications adhering strictly to WCAG 2.1 AAA standards. Collaborate with user researchers with lived disability experience.",
    accommodations: [
      { label: "Remote", icon: "🌐" },
      { label: "Wheelchair Access", icon: "♿" },
      { label: "Screen Reader Optimized", icon: "🎧" },
      { label: "Flexible Hours", icon: "⏰" },
    ],
    tagList: ["Remote", "Wheelchair Access", "Flexible Hours"],
  },
  {
    id: 2,
    title: "Frontend Developer (Accessibility)",
    company: "AbleDev Solutions",
    companyLogo: "AD",
    companyColor: "bg-teal-600",
    location: "Remote (PH & Asia)",
    type: "Full Time",
    description:
      "Build semantic, ARIA-compliant component libraries. Work alongside sign language interpreters and team members using voice interface tools.",
    accommodations: [
      { label: "Remote", icon: "🌐" },
      { label: "Flexible Hours", icon: "⏰" },
      { label: "Sign Language Support", icon: "🤟" },
    ],
    tagList: ["Remote", "Flexible Hours", "Sign Language Support"],
  },
  {
    id: 3,
    title: "Customer Support Specialist",
    company: "CareDirect Hub",
    location: "Metro Manila / Onsite",
    companyLogo: "CD",
    companyColor: "bg-indigo-600",
    type: "Full Time",
    description:
      "Handle incoming customer inquiries via live chat, email, and video calls. Fully equipped campus with tactile paving and wheelchair ramps.",
    accommodations: [
      { label: "Onsite", icon: "🏢" },
      { label: "Wheelchair Access", icon: "♿" },
      { label: "Sign Language Support", icon: "🤟" },
    ],
    tagList: ["Onsite", "Wheelchair Access", "Sign Language Support"],
  },
  {
    id: 4,
    title: "Data Analyst",
    company: "EqualData Inc.",
    companyLogo: "ED",
    companyColor: "bg-emerald-600",
    location: "Remote (Global)",
    type: "Part Time",
    description:
      "Analyze workforce inclusion metrics and construct accessible dashboards using screen-reader friendly data visualization frameworks.",
    accommodations: [
      { label: "Remote", icon: "🌐" },
      { label: "Flexible Hours", icon: "⏰" },
      { label: "Screen Reader Optimized", icon: "🎧" },
    ],
    tagList: ["Remote", "Flexible Hours"],
  },
  {
    id: 5,
    title: "Content Specialist",
    company: "Horizon Media",
    companyLogo: "HM",
    companyColor: "bg-purple-600",
    location: "Cebu City / Onsite",
    type: "Full Time",
    description:
      "Write clear, plain-language text and craft comprehensive alt-text captions for digital educational campaigns.",
    accommodations: [
      { label: "Onsite", icon: "🏢" },
      { label: "Wheelchair Access", icon: "♿" },
      { label: "Flexible Hours", icon: "⏰" },
    ],
    tagList: ["Onsite", "Wheelchair Access", "Flexible Hours"],
  },
  {
    id: 6,
    title: "Software QA Engineer",
    company: "OpenAccess Labs",
    companyLogo: "OA",
    companyColor: "bg-sky-600",
    location: "Remote",
    type: "Contract",
    description:
      "Perform automated and manual accessibility testing across assistive technology screen readers, switch devices, and eye-tracking software.",
    accommodations: [
      { label: "Remote", icon: "🌐" },
      { label: "Sign Language Support", icon: "🤟" },
      { label: "Wheelchair Access", icon: "♿" },
    ],
    tagList: ["Remote", "Sign Language Support", "Wheelchair Access"],
  },
];

export const filterChipsData = [
  { id: "Remote", label: "Remote", icon: "🌐" },
  { id: "Onsite", label: "Onsite", icon: "🏢" },
  { id: "Wheelchair Access", label: "Wheelchair Access", icon: "♿" },
  { id: "Flexible Hours", label: "Flexible Hours", icon: "⏰" },
  { id: "Sign Language Support", label: "Sign Language Support", icon: "🤟" },
];

export function getJobById(id) {
  return jobsDataset.find((job) => String(job.id) === String(id));
}

export function filterJobs(jobs, { chip, query, location }) {
  const searchQuery = (query || "").toLowerCase().trim();
  const locationQuery = (location || "").toLowerCase().trim();

  return jobs.filter((job) => {
    if (chip && !job.tagList.includes(chip)) {
      return false;
    }

    if (searchQuery) {
      const matchesQuery =
        job.title.toLowerCase().includes(searchQuery) ||
        job.company.toLowerCase().includes(searchQuery) ||
        job.description.toLowerCase().includes(searchQuery);
      if (!matchesQuery) return false;
    }

    if (locationQuery && !job.location.toLowerCase().includes(locationQuery)) {
      return false;
    }

    return true;
  });
}
