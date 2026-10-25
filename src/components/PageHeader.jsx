import Icon from './Icon.jsx';
export default function PageHeader({ eyebrow, title, description, icon = 'grid', actions, children }) {
  return <div className="page-heading">
    <div className="page-heading-main"><span className="page-symbol"><Icon name={icon} size={26} /></span><div>{eyebrow && <p className="eyebrow">{eyebrow}</p>}<h1>{title}</h1>{description && <p className="page-description">{description}</p>}</div></div>
    {actions && <div className="action-row">{actions}</div>}{children}
  </div>;
}
