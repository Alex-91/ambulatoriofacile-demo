# Isolated validator smoke test ONLY; not the product Dockerfile or a production image.
# Pin FSE_PYTHON_IMAGE to a reviewed digest before a release qualification.
ARG FSE_PYTHON_IMAGE=python:3.12-slim-bookworm
FROM ${FSE_PYTHON_IMAGE}
RUN apt-get update && apt-get install -y --no-install-recommends default-jre-headless \
    && apt-get clean
WORKDIR /opt/fse
COPY requirements.lock.txt ./
# Refuse silent source builds/platform substitutions; Linux availability is checked at build time.
RUN python -m pip install --no-cache-dir --only-binary=:all: -r requirements.lock.txt \
    && python -m pip check
COPY . /opt/fse/
RUN python -c "import hashlib,json,pathlib; r=pathlib.Path('/opt/fse'); m=json.loads((r/'bundle-manifest.json').read_text()); assert all(hashlib.sha256((r/p).read_bytes()).hexdigest()==h for p,h in m['files'].items()), 'bundle mismatch'"
ENV PYTHONDONTWRITEBYTECODE=1 PYTHONUNBUFFERED=1
USER 10001:10001
CMD ["python", "runtime-smoke.py"]
