# Psalm Static Analysis Annotator

Annotates GitHub changes with reports from Psalm static analysis.

Example workflow that uses the `--report=` output of `psalm` to create a run check with annotations.

```yml
jobs:
  report:
    runs-on: "ubuntu-latest"

    steps:
      - uses: actions/checkout@v2

      - name: Validate composer.json and composer.lock
        run: composer validate
        working-directory: ./example

      - name: Composer Install
        run: composer install --quiet --no-suggest
        working-directory: ./example

      - name: Type Check
        run: composer check -- --report=report.json --stats
        working-directory: ./example

      - name: Report Failures
        if: always()
        uses: beaucollins/psalm-annotations-action@v1

        env:
          GITHUB_TOKEN: ${{ secrets.GITHUB_TOKEN }}

        with:
          report_path: ./example/report.json
```
