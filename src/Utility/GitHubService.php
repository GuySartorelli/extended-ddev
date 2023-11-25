<?php declare(strict_types=1);

namespace GuySartorelli\ExtendedDdev\Utility;

use Github\AuthMethod;
use Github\Client as GithubClient;
use InvalidArgumentException;

class GitHubService
{
    /**
     * Get the full set of details of a GitHub pull request from an array of PR URLs or org/repo#123 formatted strings.
     */
    public static function getPullRequestDetails(array $rawPRs, string $githubToken = ''): array
    {
        if (empty($rawPRs)) {
            return [];
        }
        $client = new GithubClient();
        if ($githubToken) {
            $client->authenticate($githubToken, AuthMethod::ACCESS_TOKEN);
        }
        $prs = [];
        foreach ($rawPRs as $rawPR) {
            $parsed = static::parsePr($rawPR);
            $prDetails = $client->pullRequest()->show($parsed['org'], $parsed['repo'], $parsed['pr']);
            $composerName = static::getComposerNameForPR($client, $parsed);

            if (array_key_exists($composerName, $prs)) {
                throw new InvalidArgumentException("cannot add multiple PRs for the same pacakge: $composerName");
            }

            $prs[$composerName] = array_merge($parsed, [
                'from-org' => $prDetails['head']['user']['login'],
                'remote' => $prDetails['head']['repo']['ssh_url'],
                'prBranch' => $prDetails['head']['ref'],
                'baseBranch' => $prDetails['base']['ref'],
            ]);
        }
        return $prs;
    }

    /**
     * Parse a URL or github-shorthand PR reference into an array containing the org, repo, and pr components.
     */
    private static function parsePr(string $prRaw): array
    {
        if (!preg_match('@(?<org>[a-zA-Z0-9_-]*)/(?<repo>[a-zA-Z0-9_-]*)(?>/pull/|#)(?<pr>[0-9]*)@', $prRaw, $matches)) {
            throw new InvalidArgumentException("'$prRaw' is not a valid github PR reference.");
        }
        return $matches;
    }

    /**
     * Get the composer name of a project from the composer.json of a repo.
     */
    private static function getComposerNameForPR(GithubClient $client, array $pr): string
    {
        $composerJson = $client->repo()->contents()->download($pr['org'], $pr['repo'], 'composer.json');
        return json_decode($composerJson, true)['name'];
    }
}
