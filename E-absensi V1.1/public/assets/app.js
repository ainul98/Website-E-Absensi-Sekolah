(() => {
  const themeButtons = document.querySelectorAll("[data-theme-toggle]");
  const applyTheme = (theme) => {
    document.documentElement.dataset.theme = theme;
    const isDark = theme === "dark";
    themeButtons.forEach((button) => {
      button.setAttribute("aria-pressed", String(isDark));
      button.setAttribute("aria-label", isDark ? "Aktifkan mode terang" : "Aktifkan mode gelap");
      const icon = button.querySelector(".theme-toggle__icon");
      const label = button.querySelector(".theme-toggle__label");
      if (icon) icon.textContent = String.fromCharCode(isDark ? 9728 : 9790);
      if (label) label.textContent = isDark ? "Mode terang" : "Mode gelap";
    });
  };
  applyTheme(document.documentElement.dataset.theme || "light");
  themeButtons.forEach((button) => button.addEventListener("click", () => {
    const theme = document.documentElement.dataset.theme === "dark" ? "light" : "dark";
    applyTheme(theme);
    try { localStorage.setItem("absen-theme", theme); } catch {}
  }));

  const menuToggle = document.querySelector("[data-menu-toggle]");
  const sidebar = document.querySelector("#app-sidebar");
  menuToggle?.addEventListener("click", () => sidebar?.classList.toggle("is-open"));

  document.querySelectorAll("[data-dismiss]").forEach((button) => {
    button.addEventListener("click", () => button.closest(".flash")?.remove());
  });

  const video = document.querySelector("#face-video");
  if (!video) return;

  // ---------- Elemen & status
  const MODEL_URL = "https://cdn.jsdelivr.net/npm/@vladmandic/face-api@1.7.15/model";
  const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content ?? "";
  const cameraFrame = document.querySelector(".camera-frame");
  const cameraPlaceholder = document.querySelector("#camera-placeholder");
  const messageEl = document.querySelector("#face-message");
  const hintEl = document.querySelector("#face-hint");
  const modelEl = document.querySelector("#model-status");
  const progressEl = document.querySelector("#face-progress");
  const consent = document.querySelector("#face-consent");
  const stepEls = new Map([...document.querySelectorAll("#face-steps [data-step]")].map((el) => [el.dataset.step, el]));
  const attendButtons = [...document.querySelectorAll("[data-attend]")];
  const enrollButton = document.querySelector("[data-face-enroll]");
  const sampleCanvas = document.createElement("canvas");
  sampleCanvas.width = 64;
  sampleCanvas.height = 48;
  const sampleCtx = sampleCanvas.getContext("2d", { willReadFrequently: true });

  let stream = null;
  let modelsPromise = null;
  let modelsReady = false;
  let busy = false;
  let previewTimer = null;

  const STEP_PROGRESS = { location: 10, blink: 30, turn: 55, verify: 75, submit: 95 };

  // ---------- Tampilan kemajuan
  const setProgress = (value) => {
    const safe = Math.max(0, Math.min(100, Math.round(value)));
    progressEl.setAttribute("aria-valuenow", String(safe));
    progressEl.firstElementChild.style.width = `${safe}%`;
  };
  const resetSteps = () => {
    stepEls.forEach((el) => { el.dataset.state = "idle"; });
    setProgress(0);
  };
  const setStep = (name, state) => {
    const el = stepEls.get(name);
    if (el) el.dataset.state = state;
    if (state === "active" && STEP_PROGRESS[name] !== undefined) setProgress(STEP_PROGRESS[name]);
  };
  const showMessage = (text, kind = "info") => {
    messageEl.textContent = text;
    messageEl.dataset.kind = kind;
  };
  const setHint = (text) => { hintEl.textContent = text; };
  const syncButtons = () => {
    attendButtons.forEach((button) => {
      button.disabled = busy || button.dataset.allowed === "0";
    });
    if (enrollButton) enrollButton.disabled = busy;
  };
  const setBusy = (value) => {
    busy = value;
    syncButtons();
  };

  // ---------- Utilitas
  const pause = (ms = 140) => new Promise((resolve) => window.setTimeout(resolve, ms));
  const median = (values) => {
    const sorted = [...values].sort((a, b) => a - b);
    return sorted[Math.floor(sorted.length / 2)];
  };
  const averageDescriptor = (samples) => {
    const size = samples[0].length;
    const result = new Array(size).fill(0);
    samples.forEach((sample) => sample.forEach((value, index) => { result[index] += value / samples.length; }));
    return result;
  };
  const send = async (payload) => {
    const response = await fetch("index.php?page=face-api", {
      method: "POST",
      credentials: "same-origin",
      headers: { "Content-Type": "application/json", "X-CSRF-Token": csrfToken },
      body: JSON.stringify(payload),
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(data.error || "Permintaan tidak dapat diproses.");
    return data;
  };

  // ---------- Model pengenalan wajah (dimuat sekali, dengan indikator)
  const ensureModels = () => {
    if (!window.faceapi) {
      return Promise.reject(new Error("Pustaka pengenalan wajah belum dimuat. Periksa koneksi internet lalu muat ulang halaman."));
    }
    if (!modelsPromise) {
      const steps = [
        ["detektor cepat", () => faceapi.nets.tinyFaceDetector.loadFromUri(MODEL_URL)],
        ["detektor akurat", () => faceapi.nets.ssdMobilenetv1.loadFromUri(MODEL_URL)],
        ["titik wajah", () => faceapi.nets.faceLandmark68Net.loadFromUri(MODEL_URL)],
        ["pengenal wajah", () => faceapi.nets.faceRecognitionNet.loadFromUri(MODEL_URL)],
      ];
      modelsPromise = (async () => {
        for (let i = 0; i < steps.length; i += 1) {
          modelEl.textContent = `Memuat model ${i + 1} dari ${steps.length} (${steps[i][0]})…`;
          await steps[i][1]();
        }
        modelsReady = true;
        modelEl.textContent = "Model pengenalan siap digunakan.";
      })().catch(() => {
        modelsPromise = null;
        modelEl.textContent = "Model gagal dimuat.";
        throw new Error("Model pengenalan wajah gagal dimuat. Periksa koneksi internet lalu coba lagi.");
      });
    }
    return modelsPromise;
  };

  // ---------- Kualitas bingkai: jarak, posisi, cahaya
  const frameHint = (box) => {
    const widthRatio = box.width / video.videoWidth;
    const cx = (box.x + box.width / 2) / video.videoWidth;
    const cy = (box.y + box.height / 2) / video.videoHeight;
    if (widthRatio < 0.2) return "Dekatkan wajah ke kamera.";
    if (widthRatio > 0.8) return "Jauhkan sedikit wajah dari kamera.";
    if (Math.abs(cx - 0.5) > 0.25 || Math.abs(cy - 0.5) > 0.27) return "Posisikan wajah di tengah bingkai.";
    return null;
  };
  const lightingHint = () => {
    if (!video.videoWidth) return null;
    sampleCtx.drawImage(video, 0, 0, sampleCanvas.width, sampleCanvas.height);
    const data = sampleCtx.getImageData(0, 0, sampleCanvas.width, sampleCanvas.height).data;
    let sum = 0;
    for (let i = 0; i < data.length; i += 4) {
      sum += 0.299 * data[i] + 0.587 * data[i + 1] + 0.114 * data[i + 2];
    }
    const mean = sum / (data.length / 4);
    if (mean < 55) return "Cahaya terlalu redup. Cari area yang lebih terang.";
    if (mean > 230) return "Cahaya terlalu kuat atau wajah membelakangi cahaya.";
    return null;
  };

  // Deteksi cepat untuk panduan dan pemeriksaan keaktifan
  const detectLive = async () => {
    const faces = await faceapi
      .detectAllFaces(video, new faceapi.TinyFaceDetectorOptions({ inputSize: 320, scoreThreshold: 0.5 }))
      .withFaceLandmarks();
    if (faces.length > 1) throw new Error("Hanya satu wajah yang boleh terlihat di kamera.");
    return faces[0] || null;
  };

  // Panduan real-time sebelum absensi dimulai
  const startPreview = () => {
    stopPreview();
    previewTimer = window.setInterval(async () => {
      if (busy || !stream || !modelsReady || video.readyState < 2) return;
      try {
        const face = await detectLive();
        if (!face) {
          setHint("Wajah belum terdeteksi. Hadapkan wajah ke kamera.");
          return;
        }
        const hint = frameHint(face.detection.box) ?? lightingHint();
        setHint(hint ?? "Wajah terdeteksi dengan baik. Silakan pilih absen masuk atau absen pulang.");
      } catch (error) {
        setHint(error.message);
      }
    }, 450);
  };
  const stopPreview = () => {
    if (previewTimer) window.clearInterval(previewTimer);
    previewTimer = null;
  };

  // ---------- Pemeriksaan keaktifan: kedip dan tolehan acak
  const eyeAspectRatio = (eye) => {
    const distance = (a, b) => Math.hypot(a.x - b.x, a.y - b.y);
    const width = distance(eye[0], eye[3]);
    if (!width) return 0;
    return (distance(eye[1], eye[5]) + distance(eye[2], eye[4])) / (2 * width);
  };
  const eyeCenter = (points) => ({
    x: points.reduce((sum, p) => sum + p.x, 0) / points.length,
    y: points.reduce((sum, p) => sum + p.y, 0) / points.length,
  });
  // Perkiraan arah hadap: posisi hidung relatif terhadap tengah kedua mata
  const yawRatio = (face) => {
    const nose = face.landmarks.positions[30];
    const left = eyeCenter(face.landmarks.getLeftEye());
    const right = eyeCenter(face.landmarks.getRightEye());
    const span = right.x - left.x;
    if (Math.abs(span) < 1) return null;
    return (nose.x - (left.x + right.x) / 2) / span;
  };

  const runLiveness = async () => {
    const challenge = await send({ operation: "liveness_challenge" });
    const instructions = {
      blink: "Kedipkan mata Anda satu kali dengan jelas.",
      turn_head: "Tolehkan kepala sedikit ke satu sisi, lalu kembali menghadap lurus.",
    };
    const completed = [];
    for (const step of challenge.steps) {
      const stepName = step === "blink" ? "blink" : "turn";
      setStep(stepName, "active");
      setHint(instructions[step]);
      const deadline = Date.now() + 12000;
      let phase = 0;
      let openRef = 0;
      let stable = 0;
      let baseline = null;
      const yawSamples = [];
      let passed = false;

      while (Date.now() < deadline && !passed) {
        const face = await detectLive();
        if (!face) {
          setHint("Wajah tidak terlihat. " + instructions[step]);
          await pause();
          continue;
        }
        if (step === "blink") {
          const ear = (eyeAspectRatio(face.landmarks.getLeftEye()) + eyeAspectRatio(face.landmarks.getRightEye())) / 2;
          openRef = Math.max(openRef, ear);
          if (openRef > 0.12) {
            if (phase === 0 && ear < openRef * 0.6) phase = 1;
            else if (phase === 1 && ear >= openRef * 0.8) passed = true;
          }
        } else {
          const yaw = yawRatio(face);
          if (yaw === null) {
            await pause();
            continue;
          }
          if (baseline === null) {
            yawSamples.push(yaw);
            if (yawSamples.length >= 5) {
              baseline = median(yawSamples);
              setHint("Tolehkan kepala perlahan ke satu sisi, lalu kembali lurus.");
            }
          } else {
            const offset = Math.abs(yaw - baseline);
            if (phase === 0) {
              stable = offset > 0.18 ? stable + 1 : 0;
              if (stable >= 2) { phase = 1; stable = 0; }
            } else {
              stable = offset < 0.08 ? stable + 1 : 0;
              if (stable >= 2) passed = true;
            }
          }
        }
        await pause();
      }
      if (!passed) {
        setStep(stepName, "error");
        throw new Error(step === "blink"
          ? "Kedipan belum terdeteksi. Pastikan wajah terlihat jelas, lalu ulangi."
          : "Tolehan kepala belum terdeteksi. Tolehkan kepala lebih jelas, lalu ulangi.");
      }
      setStep(stepName, "done");
      completed.push(step);
    }
    return { challenge_id: challenge.challenge_id, completed_steps: completed };
  };

  // ---------- Pencocokan wajah: tiga bingkai berkualitas, lalu dirata-rata
  const captureDescriptor = async () => {
    const samples = [];
    const deadline = Date.now() + 12000;
    while (samples.length < 3 && Date.now() < deadline) {
      const result = await faceapi
        .detectSingleFace(video, new faceapi.SsdMobilenetv1Options({ minConfidence: 0.6 }))
        .withFaceLandmarks()
        .withFaceDescriptor();
      if (!result) {
        setHint("Wajah belum jelas. Tahan posisi kepala.");
        await pause(200);
        continue;
      }
      const hint = frameHint(result.detection.box) ?? lightingHint();
      if (hint) {
        setHint(hint);
        await pause(200);
        continue;
      }
      samples.push(Array.from(result.descriptor));
      setHint(`Mencocokkan wajah… bingkai ${samples.length} dari 3.`);
      setProgress(STEP_PROGRESS.verify + samples.length * 4);
      await pause(250);
    }
    if (samples.length < 3) {
      throw new Error("Wajah belum cukup jelas untuk dicocokkan. Atur cahaya dan posisi, lalu coba lagi.");
    }
    return averageDescriptor(samples);
  };

  // ---------- GPS
  const locationFromDevice = () => new Promise((resolve, reject) => {
    if (!navigator.geolocation) {
      const error = new Error("Browser ini tidak mendukung lokasi GPS. Gunakan browser terbaru melalui localhost atau HTTPS.");
      error.locationFailureReason = "unsupported";
      reject(error);
      return;
    }
    navigator.geolocation.getCurrentPosition(
      (position) => resolve({
        latitude: position.coords.latitude,
        longitude: position.coords.longitude,
        accuracy: position.coords.accuracy,
        timestamp: position.timestamp,
      }),
      (error) => {
        const messages = {
          1: "Izin lokasi ditolak. Aktifkan izin lokasi untuk situs ini agar dapat melakukan absensi.",
          2: "Lokasi GPS perangkat tidak tersedia. Periksa pengaturan lokasi lalu coba lagi.",
          3: "Waktu mendapatkan lokasi habis. Pindah ke area dengan sinyal GPS lebih baik lalu coba lagi.",
        };
        const reasons = { 1: "permission_denied", 2: "position_unavailable", 3: "timeout" };
        const locationError = new Error(messages[error.code] || "Lokasi GPS tidak dapat diperoleh.");
        locationError.locationFailureReason = reasons[error.code] || "position_unavailable";
        reject(locationError);
      },
      { enableHighAccuracy: true, maximumAge: 0, timeout: 20000 },
    );
  });

  // ---------- Alur utama
  const requireCamera = () => {
    if (!stream || !modelsReady) {
      showMessage("Aktifkan kamera terlebih dahulu dan tunggu model pengenalan siap.", "error");
      return false;
    }
    return true;
  };

  const runAttendance = async (action) => {
    if (busy || !requireCamera()) return;
    const label = action === "check_in" ? "absen masuk" : "absen pulang";
    setBusy(true);
    resetSteps();
    setStep("camera", "done");
    stopPreview();
    try {
      setStep("location", "active");
      showMessage(`Mengambil lokasi GPS untuk ${label}…`);
      const location = await locationFromDevice();
      if (location.accuracy > 50) {
        throw new Error(`Akurasi GPS ${Math.round(location.accuracy)} m. Harus 50 m atau lebih baik. Pindah ke area terbuka lalu coba lagi.`);
      }
      setStep("location", "done");

      showMessage("Pemeriksaan keaktifan: ikuti instruksi di bawah.");
      const liveness = await runLiveness();

      setStep("verify", "active");
      showMessage("Mencocokkan wajah dengan template Anda…");
      const descriptor = await captureDescriptor();
      setStep("verify", "done");

      setProgress(STEP_PROGRESS.submit);
      showMessage("Mengirim data absensi ke server…");
      const result = await send({ operation: "scan", action, descriptor, location, liveness });
      setProgress(100);
      setHint("Selesai.");
      showMessage(result.message, "success");
      window.setTimeout(() => window.location.reload(), 1500);
    } catch (error) {
      if (error.locationFailureReason) {
        send({ operation: "location_failure", reason: error.locationFailureReason }).catch(() => {});
      }
      const active = [...stepEls.values()].find((el) => el.dataset.state === "active");
      if (active) active.dataset.state = "error";
      showMessage(error.message || `Gagal mencatat ${label}.`, "error");
      setHint("Perbaiki kendala di atas, lalu coba lagi.");
      setBusy(false);
      if (stream) startPreview();
    }
  };

  const runEnrollment = async () => {
    if (!consent.checked) {
      showMessage("Centang persetujuan biometrik sebelum mendaftarkan wajah.", "error");
      return;
    }
    if (!stream || !modelsReady) {
      showMessage("Aktifkan kamera terlebih dahulu dan tunggu model pengenalan siap.", "error");
      return;
    }
    setBusy(true);
    resetSteps();
    setStep("camera", "done");
    setStep("location", "skip");
    stopPreview();
    try {
      showMessage("Pendaftaran wajah: ikuti instruksi keaktifan.");
      const liveness = await runLiveness();
      setStep("verify", "active");
      const descriptor = await captureDescriptor();
      setStep("verify", "done");
      setProgress(95);
      const result = await send({ operation: "enroll", descriptor, consent: true, liveness });
      setProgress(100);
      showMessage(result.message, "success");
      window.setTimeout(() => window.location.reload(), 1500);
    } catch (error) {
      const active = [...stepEls.values()].find((el) => el.dataset.state === "active");
      if (active) active.dataset.state = "error";
      showMessage(error.message || "Pendaftaran wajah gagal.", "error");
      setBusy(false);
      if (stream) startPreview();
    }
  };

  // ---------- Kejadian
  document.querySelector("[data-camera-start]")?.addEventListener("click", async (event) => {
    const button = event.currentTarget;
    button.disabled = true;
    try {
      if (!navigator.mediaDevices?.getUserMedia) {
        throw new Error("Browser ini tidak mendukung kamera. Gunakan browser terbaru melalui localhost atau HTTPS.");
      }
      if (!stream) {
        stream = await navigator.mediaDevices.getUserMedia({
          audio: false,
          video: { facingMode: "user", width: { ideal: 720 }, height: { ideal: 540 } },
        });
        video.srcObject = stream;
        await video.play();
        cameraFrame.classList.add("is-live");
        cameraPlaceholder.setAttribute("aria-hidden", "true");
        setStep("camera", "done");
      }
      showMessage("Kamera aktif. Memuat model pengenalan wajah…");
      await ensureModels();
      showMessage("Kamera dan model siap. Pilih absen masuk atau absen pulang.", "success");
      startPreview();
    } catch (error) {
      showMessage(error.message || "Kamera tidak dapat diakses.", "error");
      setStep("camera", "error");
    } finally {
      button.disabled = false;
    }
  });

  attendButtons.forEach((button) => {
    button.addEventListener("click", () => runAttendance(button.dataset.attend));
  });

  enrollButton?.addEventListener("click", runEnrollment);

  document.querySelector("[data-face-revoke]")?.addEventListener("click", async (event) => {
    if (!window.confirm("Cabut template biometrik Anda? Anda perlu mendaftar ulang sebelum dapat absen mandiri.")) return;
    const button = event.currentTarget;
    button.disabled = true;
    try {
      const result = await send({ operation: "revoke" });
      showMessage(result.message, "success");
      window.setTimeout(() => window.location.reload(), 900);
    } catch (error) {
      showMessage(error.message || "Persetujuan tidak dapat dicabut.", "error");
      button.disabled = false;
    }
  });

  setStep("camera", "idle");
  syncButtons();
  window.addEventListener("pagehide", () => {
    stopPreview();
    stream?.getTracks().forEach((track) => track.stop());
  });
})();
