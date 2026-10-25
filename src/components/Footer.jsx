import { Link } from "react-router-dom";
import { useUi } from "../context/UiContext.jsx";

const configuredEmail = (import.meta.env.VITE_SUPPORT_EMAIL || '').trim();
const supportEmail = /^[^\s@<>]+@[^\s@<>]+\.[^\s@<>]+$/.test(configuredEmail) ? configuredEmail : '';
const copyrightYear = new Date().getFullYear();

export default function Footer() {
  const { openModal } = useUi();
  function information(title, text) {
    openModal({ title, content: <p className="leading-relaxed whitespace-pre-wrap">{text}</p> });
  }
  return <footer className="bg-brand-blue-dark text-white py-6">
    <div className="max-w-[1440px] mx-auto px-6 lg:px-12 space-y-5">
      <div className="grid md:grid-cols-3 gap-6">
        <div><p className="text-lg font-black">PWDWork</p><p className="text-gray-300">An employment platform for persons with disabilities. Workplace accessibility information is reported by employers; confirm arrangements before relying on it.</p></div>
        <nav aria-label="Footer navigation" className="flex flex-wrap gap-x-5 gap-y-3 items-start">
          <Link to="/">Home</Link><Link to="/jobs">Find Jobs</Link><Link to="/employers">For Employers</Link><Link to="/about">About PWDWork</Link>
          <button type="button" onClick={() => information('Accessibility statement', 'PWDWork targets WCAG 2.2 AA principles. Text size, contrast, dark mode, reduced motion, reading font and cursor settings are available above. Independent conformance and manual screen-reader testing are pending. Resume PDFs have selectable text but are not validated PDF/UA. Contact the configured project operator to report access barriers.')}>Accessibility statement</button>
        </nav>
        <div><h2 className="font-bold">Contact and support</h2>{supportEmail ? <a className="underline" href={`mailto:${supportEmail}`}>{supportEmail}</a> : <p>Support contact has not yet been configured by the project operator.</p>}<p className="text-sm text-gray-300">This platform does not provide an emergency, telephone or sign-language hotline.</p></div>
      </div>
      <p className="border-t border-white/30 pt-4">Project goals relate to UN SDG 8 (Decent Work and Economic Growth) and SDG 10 (Reduced Inequalities). No UN certification or endorsement is claimed.</p>
      <div className="flex flex-wrap gap-5 items-center text-sm">
        <p>© {copyrightYear} PWDWork</p>
        <button type="button" className="underline" onClick={() => information('Privacy information — policy pending', 'Account and profile information is stored to provide job applications and related features. Employers can see professional profile information associated with applications to their jobs. Profile accommodation preferences require separate consent. Accommodation discussions and builder resume snapshots are restricted to authorized application participants; withdrawal/revocation blocks future employer access. Downloaded copies cannot be recalled. Accessibility preferences are stored in this browser.\n\nThe project operator must publish an approved privacy policy identifying the operator, contact, retention periods, lawful handling arrangements and rights-request process before public launch. This feature description is not a completed legal policy. Avoid submitting real personal data to a demonstration environment.')}>Privacy information (policy pending)</button>
        <button type="button" className="underline" onClick={() => information('Terms of service — publication pending', 'Owner-approved terms of service have not been published. Operator identity, acceptable use, moderation/appeals, contact channels and service responsibilities require review before public registration. A job listing or employer review is not a guarantee of employment or workplace accessibility.')}>Terms (publication pending)</button>
      </div>
    </div>
  </footer>;
}
