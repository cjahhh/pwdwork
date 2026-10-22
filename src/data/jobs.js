// Historical demo reference only. Runtime jobs come from Laravel; see PwdWorkSeeder.php.
export const jobsDataset = [
  {
    id: 1,
    title: "Customer Service Representative",
    company: "CareDirect Hub",
    companyLogo: "CD",
    companyColor: "bg-blue-600",
    location: "Remote or Metro Manila",
    type: "Full Time",
    description: "Answer customer questions by phone, chat, or email and help resolve everyday service concerns. Training and scripts are provided.",
    accommodations: [
      { label: "Remote", icon: "\\u{1F310}" },
      { label: "Flexible Hours", icon: "\\u{23F0}" },
      { label: "Screen Reader Optimized", icon: "\\u{1F3A7}" },
    ],
    tagList: ["Remote", "Flexible Hours"],
  },
  {
    id: 2,
    title: "Warehouse Inventory Assistant",
    company: "Mabuhay Distribution",
    companyLogo: "MD",
    companyColor: "bg-teal-600",
    location: "Quezon City / Onsite",
    type: "Part Time",
    description: "Count and label incoming goods, update simple inventory sheets, and prepare small orders. Seated and light-duty tasks are available.",
    accommodations: [
      { label: "Onsite", icon: "\\u{1F3E2}" },
      { label: "Wheelchair Access", icon: "\\u{267F}" },
      { label: "Flexible Hours", icon: "\\u{23F0}" },
    ],
    tagList: ["Onsite", "Wheelchair Access", "Flexible Hours"],
  },
  {
    id: 3,
    title: "Bakery Production Assistant",
    company: "Good Grain Bakery",
    companyLogo: "GG",
    companyColor: "bg-amber-600",
    location: "Pasig City / Onsite",
    type: "Part Time",
    description: "Pack baked goods, place labels, and keep the work area organized. Tasks can be adjusted for seated work and shorter shifts.",
    accommodations: [
      { label: "Onsite", icon: "\\u{1F3E2}" },
      { label: "Wheelchair Access", icon: "\\u{267F}" },
      { label: "Flexible Hours", icon: "\\u{23F0}" },
    ],
    tagList: ["Onsite", "Wheelchair Access", "Flexible Hours"],
  },
  {
    id: 4,
    title: "Receptionist and Records Clerk",
    company: "Community Health Center",
    companyLogo: "CH",
    companyColor: "bg-emerald-600",
    location: "Cebu City / Onsite",
    type: "Full Time",
    description: "Welcome visitors, answer calls, organize appointment records, and enter basic information. A quiet desk and accessible workstation are provided.",
    accommodations: [
      { label: "Onsite", icon: "\\u{1F3E2}" },
      { label: "Wheelchair Access", icon: "\\u{267F}" },
      { label: "Flexible Hours", icon: "\\u{23F0}" },
    ],
    tagList: ["Onsite", "Wheelchair Access", "Flexible Hours"],
  },
  {
    id: 5,
    title: "Retail Stock and Checkout Assistant",
    company: "Everyday Market",
    companyLogo: "EM",
    companyColor: "bg-purple-600",
    location: "Davao City / Onsite",
    type: "Part Time",
    description: "Restock light products, keep shelves tidy, and assist customers at the checkout counter. Flexible shifts and an accessible entrance are available.",
    accommodations: [
      { label: "Onsite", icon: "\\u{1F3E2}" },
      { label: "Wheelchair Access", icon: "\\u{267F}" },
      { label: "Flexible Hours", icon: "\\u{23F0}" },
    ],
    tagList: ["Onsite", "Wheelchair Access", "Flexible Hours"],
  },
  {
    id: 6,
    title: "Community Garden Assistant",
    company: "GreenPath Cooperative",
    companyLogo: "GP",
    companyColor: "bg-green-600",
    location: "Antipolo / Onsite",
    type: "Contract",
    description: "Help water plants, sort seeds, prepare produce boxes, and maintain garden paths. Adaptive tools and task coaching are available.",
    accommodations: [
      { label: "Onsite", icon: "\\u{1F3E2}" },
      { label: "Flexible Hours", icon: "\\u{23F0}" },
      { label: "Wheelchair Access", icon: "\\u{267F}" },
    ],
    tagList: ["Onsite", "Flexible Hours", "Wheelchair Access"],
  },
];

export const filterChipsData = [
  { id: "Remote", label: "Remote", icon: "\\u{1F310}" },
  { id: "Onsite", label: "Onsite", icon: "\\u{1F3E2}" },
  { id: "Wheelchair Access", label: "Wheelchair Access", icon: "\\u{267F}" },
  { id: "Flexible Hours", label: "Flexible Hours", icon: "\\u{23F0}" },
  { id: "Sign Language Support", label: "Sign Language Support", icon: "\\u{1F91F}" },
];

export function getJobById(id) {
  return jobsDataset.find((job) => String(job.id) === String(id));
}

export function filterJobs(jobs, { chip, query, location }) {
  const searchQuery = (query || "").toLowerCase().trim();
  const locationQuery = (location || "").toLowerCase().trim();

  return jobs.filter((job) => {
    if (chip && !job.tagList.includes(chip)) return false;
    if (searchQuery) {
      const matchesQuery = job.title.toLowerCase().includes(searchQuery) || job.company.toLowerCase().includes(searchQuery) || job.description.toLowerCase().includes(searchQuery);
      if (!matchesQuery) return false;
    }
    if (locationQuery && !job.location.toLowerCase().includes(locationQuery)) return false;
    return true;
  });
}
