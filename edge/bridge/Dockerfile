FROM python:3.12-slim
# ffmpeg extracts a few frames from each clip: the AI judges the movement, not a single photo.
RUN apt-get update && apt-get install -y --no-install-recommends ffmpeg && rm -rf /var/lib/apt/lists/*
WORKDIR /app
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt
COPY bridge.py .
CMD ["python", "-u", "bridge.py"]
