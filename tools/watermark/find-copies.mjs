/**
 * Finds unauthorized deployments and copies of Aurora by looking for its
 * watermark across the web and on GitHub.
 *
 * Aurora's public pages already carry a distinctive, stable marker, set in
 * `src/Core/templates/Frontend/themes/default/layout.html.twig`:
 *
 *     <!-- Built with Aurora · © axelraboit · https://github.com/AxelRaboit -->
 *     <meta name="generator" content="Aurora by axelraboit">
 *
 * The licence (see LICENSE) makes the code viewable only, reuse forbidden.
 * This tool is how you notice when someone reuses it anyway: it searches for
 * that marker, then filters out your own domains and repositories, so what is
 * left is what you investigate.
 *
 * Two layers, because they catch different things:
 *   - GitHub (run automatically here via the `gh` CLI): forks and copies of
 *     the source hosted on GitHub.
 *   - The open web (ready-to-open search URLs): deployed sites running the
 *     code. HTML comments and <meta> are not indexed by Google, so the source
 *     marker is searched through PublicWWW, a source-code search engine built
 *     for exactly this ("which sites embed this snippet"). A visible footer
 *     marker, once enabled, is searched through Google/Bing as well.
 *
 * What it does NOT catch, honestly: a copy that is not indexed anywhere (an
 * internal or password-walled deployment), or one where the thief stripped the
 * marker. The runtime beacon is the complementary layer for those.
 *
 * Nothing here touches anyone else's machine: it only queries public search
 * engines and the GitHub API, read-only.
 *
 * Usage:
 *   node tools/watermark/find-copies.mjs          # GitHub scan + search links
 *   node tools/watermark/find-copies.mjs --links  # only print the search URLs
 *
 * Your own domains and GitHub owners are "known" and filtered out. Extend the
 * built-in defaults with an optional, git-ignorable file:
 *   tools/watermark/known-hosts.json
 *   { "domains": ["example.com"], "owners": ["SomeOrg"] }
 */

import { execFile } from "node:child_process";
import { promisify } from "node:util";
import { readFile } from "node:fs/promises";
import { resolve, dirname } from "node:path";
import { fileURLToPath } from "node:url";

const execFileAsync = promisify(execFile);
const here = dirname(fileURLToPath(import.meta.url));

// The needles. These MUST stay in sync with the marker in layout.html.twig.
const MARKER_META = "Aurora by axelraboit";
const MARKER_GITHUB = "github.com/AxelRaboit";

// Your own footprint. Anything matching this is "known" and filtered out of the
// report; everything else is a lead. Mirrors the allowlist the beacon's
// back-office screen will hold later.
const DEFAULT_KNOWN_DOMAINS = [
  "axelraboit.fr",
  "app.axelraboit.fr",
  "localhost",
  "127.0.0.1",
];
const DEFAULT_KNOWN_OWNERS = ["AxelRaboit"];
const SELF_REPOS = ["AxelRaboit/aurora-core", "AxelRaboit/aurora-client"];

async function loadKnown() {
  const domains = new Set(DEFAULT_KNOWN_DOMAINS);
  const owners = new Set(DEFAULT_KNOWN_OWNERS);
  try {
    const raw = await readFile(resolve(here, "known-hosts.json"), "utf8");
    const extra = JSON.parse(raw);
    for (const d of extra.domains ?? []) domains.add(String(d).toLowerCase());
    for (const o of extra.owners ?? []) owners.add(String(o).toLowerCase());
  } catch {
    // No override file: the defaults are enough for today.
  }
  return { domains, owners: new Set([...owners].map((o) => o.toLowerCase())) };
}

const isKnownOwner = (fullName, known) =>
  known.owners.has(String(fullName).split("/")[0]?.toLowerCase());

async function gh(args) {
  try {
    const { stdout } = await execFileAsync("gh", args, {
      maxBuffer: 10 * 1024 * 1024,
    });
    return stdout;
  } catch (error) {
    return { error };
  }
}

async function ghAvailable() {
  const out = await gh(["auth", "status"]);
  return typeof out === "string" || (out?.error?.code !== "ENOENT" ?? false);
}

// Forks are a normal GitHub feature, but a fork that has been detached, renamed
// or deployed is worth a look. We list them and flag the ones not owned by you.
async function listForks(repo) {
  const out = await gh([
    "api",
    `repos/${repo}/forks`,
    "--paginate",
    "--jq",
    ".[].full_name",
  ]);
  if (typeof out !== "string") return [];
  return out.split("\n").map((s) => s.trim()).filter(Boolean);
}

// Global code search for the marker string: copies of the source uploaded as
// their own repository (code search excludes forks, so it complements the
// fork listing above). Requires the string to be indexed by GitHub.
async function codeSearch(needle) {
  const out = await gh([
    "api",
    "-X",
    "GET",
    "search/code",
    "-f",
    `q="${needle}"`,
    "--jq",
    ".items[].repository.full_name",
  ]);
  if (typeof out !== "string") return { results: [], error: out?.error };
  const results = [...new Set(out.split("\n").map((s) => s.trim()).filter(Boolean))];
  return { results, error: null };
}

function searchLinks() {
  const q = encodeURIComponent(`"${MARKER_META}"`);
  const gh = encodeURIComponent(`"${MARKER_GITHUB}"`);
  return [
    ["PublicWWW (site source, le plus utile)", `https://publicwww.com/websites/${q}/`],
    ["Google (marqueur visible, une fois le pied de page activé)", `https://www.google.com/search?q=${q}`],
    ["Bing", `https://www.bing.com/search?q=${q}`],
    ["GitHub code (web)", `https://github.com/search?type=code&q=${gh}`],
  ];
}

function section(title) {
  console.log(`\n${title}\n${"-".repeat(title.length)}`);
}

async function main() {
  const linksOnly = process.argv.includes("--links");
  console.log("Aurora watermark scan");
  console.log(`Marker: "${MARKER_META}" / ${MARKER_GITHUB}`);

  const known = await loadKnown();

  if (!linksOnly) {
    if (await ghAvailable()) {
      section("GitHub forks (flagged = not yours)");
      for (const repo of SELF_REPOS) {
        const forks = await listForks(repo);
        if (forks.length === 0) {
          console.log(`  ${repo}: no forks`);
          continue;
        }
        for (const fork of forks) {
          const mark = isKnownOwner(fork, known) ? "  " : "⚠ ";
          console.log(`  ${mark}${repo} -> ${fork}`);
        }
      }

      section("GitHub code copies (marker uploaded as its own repo)");
      const { results, error } = await codeSearch(MARKER_META);
      if (error) {
        console.log("  (code search unavailable: rate limit or not indexed yet)");
      } else {
        const leads = results.filter(
          (r) => !isKnownOwner(r, known) && !SELF_REPOS.includes(r),
        );
        if (leads.length === 0) console.log("  none outside your own repos");
        else for (const r of leads) console.log(`  ⚠ ${r}`);
      }
    } else {
      section("GitHub");
      console.log("  `gh` CLI not found or not authenticated — skipping.");
      console.log("  Install/login with `gh auth login`, or use the links below.");
    }
  }

  section("Open-web searches (open these in a browser)");
  for (const [label, url] of searchLinks()) console.log(`  ${label}\n    ${url}`);

  section("Known (filtered out)");
  console.log(`  domains: ${[...known.domains].join(", ")}`);
  console.log(`  owners:  ${[...known.owners].join(", ")}`);
  console.log(
    "\nTip: set a Google Alert on the marker for passive, ongoing detection.",
  );
}

main().catch((error) => {
  console.error(error);
  process.exit(1);
});
