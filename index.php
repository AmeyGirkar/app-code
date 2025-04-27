name: ci

env:
  CONFIG_REPO_NAME: app-helmfile

on:
  pull_request:
    types: [labeled, opened, synchronize]
  push:
    branches: [ "**" ]

jobs:
  unit-tests:
    runs-on: ubuntu-latest
    steps:
      - name: Checkout Code
        uses: actions/checkout@v4

      - name: Run Unit Tests
        run: |
          echo "Implement unit tests if applicable."
          echo "This stage is a sample placeholder."

  docker-build-push:
    runs-on: ubuntu-latest
    needs: unit-tests
    env:
      VERSION: ${{ github.run_number }}
    steps:
      - name: Checkout Code
        uses: actions/checkout@v4

      - name: Set up Docker Buildx
        uses: docker/setup-buildx-action@v3

      - name: Login to DockerHub
        uses: docker/login-action@v3
        with:
          username: ${{ secrets.DOCKERHUB_USERNAME }}
          password: ${{ secrets.DOCKERHUB_TOKEN }}

      - name: Build and Push Docker Image
        run: |
          docker build -t app .
          docker tag app ameyg/app:$VERSION
          docker push ameyg/app:$VERSION

  setup:
    runs-on: ubuntu-latest
    needs: docker-build-push
    outputs:
      environments: ${{ steps.get-labels.outputs.environments }}
    steps:
      - name: Get environment labels
        id: get-labels
        run: |
          if [ "${{ github.event_name }}" = "pull_request" ]; then
            # Get labels from pull_request event
            LABELS_JSON='${{ toJSON(github.event.pull_request.labels.*.name) }}'
          else
            # Push event: find associated PR and get labels
            BRANCH=${GITHUB_REF#refs/heads/}
            REPO_OWNER=${GITHUB_REPOSITORY%/*}
            REPO_NAME=${GITHUB_REPOSITORY#*/}
            GITHUB_TOKEN=${{ secrets.GITHUB_TOKEN }}

            echo "Fetching PRs for branch $BRANCH..."
            PRS=$(curl -s -H "Authorization: Bearer $GITHUB_TOKEN" \
              -H "Accept: application/vnd.github.v3+json" \
              "https://api.github.com/repos/$REPO_OWNER/$REPO_NAME/pulls?head=$REPO_OWNER:$BRANCH")

            if [ "$(echo "$PRS" | jq length)" -gt 0 ]; then
              LABELS_JSON=$(echo "$PRS" | jq '[.[0].labels[].name]')
            else
              LABELS_JSON='[]'
            fi
          fi

          VALID_LABELS='["sandbox","qa","preprod","qa-test","sandbox-test"]'
          FILTERED_LABELS=$(echo "$LABELS_JSON" | jq --argjson valid "$VALID_LABELS" '
            map(select(. as $label | $valid | index($label) != null))
          ')

          LABELS_CSV=$(echo "$FILTERED_LABELS" | jq -r 'join(",")')
          FORMATTED_LABELS=$(echo "$LABELS_CSV" | jq -R 'split(",") | map(select(. != ""))' | jq -c '.')

          echo "Valid environments: $FORMATTED_LABELS"
          echo "environments=$FORMATTED_LABELS" >> $GITHUB_OUTPUT

  promote-environments:
    if: ${{ needs.setup.outputs.environments != '[]' }}
    needs: setup
    runs-on: ubuntu-latest
    strategy:
      matrix:
        environment: ${{ fromJSON(needs.setup.outputs.environments) }}
    env:
      PR_SOURCE_BRANCH: ${{ github.event.pull_request.head.ref }}
    steps:
      - name: Set up Git
        run: |
          git config --global user.email "argocd-user@argocd.com"
          git config --global user.name "argocd-user"

      - name: Clone GitOps Config Repo
        run: |
          echo "Cloning config repo $CONFIG_REPO_NAME"
          git clone https://oauth2:${{ secrets.GH_PAT }}@github.com/${{ github.repository_owner }}/$CONFIG_REPO_NAME.git
          cd $CONFIG_REPO_NAME

          echo "Processing environment: ${{ matrix.environment }}"
          
          if [[ "${{ matrix.environment }}" == "sandbox" ]]; then
            git checkout sandbox
          elif [[ "${{ matrix.environment }}" == "qa" ]]; then
            git checkout qa
          elif [[ "${{ matrix.environment }}" == "preprod" ]]; then
            git checkout preprod
          elif [[ "${{ matrix.environment }}" == "qa-test" ]]; then
            BRANCH_NAME="itr-${PR_SOURCE_BRANCH}-qa-test"
            git checkout -b "$BRANCH_NAME"
          elif [[ "${{ matrix.environment }}" == "sandbox-test" ]]; then
            BRANCH_NAME="itr-${PR_SOURCE_BRANCH}-sandbox-test"
            git checkout -b "$BRANCH_NAME"
          fi

      - name: Update Image Tag
        run: |
          cd $CONFIG_REPO_NAME
          sed -i "s,tag:.*,tag: ${{ github.run_number }}," values.yaml
          cat values.yaml

      - name: Commit and Push Changes
        run: |
          cd $CONFIG_REPO_NAME
          git add .
          git commit -m "Update image tag to ${{ github.run_number }} in ${{ matrix.environment }} environment"
          git push
