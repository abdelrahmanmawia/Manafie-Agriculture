// An account's level on a domain ('pointage' or 'stock'): 'aucun', 'consultation' (read and
// export) or 'saisie' (everything) — same rule as User::acces() on the server, which refuses a
// write without saisie anyway; these only decide what the pages show.
export const acces = (user, domaine) => (user?.role === 'super_admin' ? 'saisie' : (user?.[`acces_${domaine}`] || 'aucun'));

export const peutConsulter = (user, domaine) => acces(user, domaine) !== 'aucun';

export const peutSaisir = (user, domaine) => acces(user, domaine) === 'saisie';
