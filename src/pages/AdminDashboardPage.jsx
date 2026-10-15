function AdminDashboardPage() {
  return (
    <main className="bg-brand-gray-bg py-16">
      <div className="max-w-4xl mx-auto px-6 space-y-4">
        <h1 className="text-3xl font-black text-brand-dark">Admin Dashboard</h1>
        <p className="text-gray-600">
          Employer verification, user management, and report cards will be added in a later phase.
        </p>
        <div className="grid md:grid-cols-3 gap-4">
          {["Verification", "Users", "Reports"].map((item) => (
            <div key={item} className="bg-white p-6 rounded-2xl border border-gray-200 shadow-soft-lg">
              <h2 className="font-black text-brand-blue">{item}</h2>
              <p className="text-sm text-gray-600 mt-2">Coming soon.</p>
            </div>
          ))}
        </div>
      </div>
    </main>
  );
}

export default AdminDashboardPage;
